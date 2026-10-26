<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * « L'API ne marche plus » — surtout « crédit insuffisant » chez OpenAI,
 * Anthropic et Mistral — de la réponse du fournisseur jusqu'à l'écran.
 *
 * Ce que ce test garde :
 *   1. la CAUSE est reconnue pour chaque forme réelle de refus faute de crédit
 *      (statuts 400, 402, 429 — dont le plafond de dépense d'Anthropic, rendu
 *      en 429 `rate_limit_error` comme une limite de débit, qui s'affichait
 *      « Service momentanément saturé, patientez ») ;
 *   2. par les VRAIS adaptateurs, sur un faux fournisseur HTTP local (chat,
 *      recherche Internet, boucle d'outils) — pas seulement sur des chaînes ;
 *   3. le message est lisible par quelqu'un qui n'a jamais administré MIA
 *      (aucun mot technique) et invite à prévenir un administrateur ;
 *   4. l'administration est avertie (ProviderIncident → points à régler), et
 *      le constat s'éteint quand le compte répond de nouveau ;
 *   5. l'encadré du chat propose « Prévenir un administrateur » avec les
 *      administrateurs nommés et un message déjà rédigé (code exécuté sous
 *      node) — un administrateur voit le lien de la page où régler la cause ;
 *   6. dictée et lecture à voix haute disent la même cause, sans le message
 *      brut d'OpenAI.
 */
final class ProviderCreditErrorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    protected function setUp(): void
    {
        ProviderIncident::useMemoryStore();
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        ProviderIncident::useMemoryStore();
        I18n::use('fr');
        LlmCatalog::flushCache();
    }

    // ------------------------------------------------------------------
    // 1. Chaque forme de refus faute de crédit
    // ------------------------------------------------------------------

    /**
     * Corps réels (documentation des fournisseurs) — sauf Mistral, dont la
     * documentation ne donne ni le corps d'un 402 ni celui du plafond mensuel :
     * formes supposées, au format d'erreur documenté de Mistral.
     *
     * @return array<string, array{string, string, int, string}> fournisseur, libellé, statut, corps
     */
    public static function refusFauteDeCredit(): array
    {
        return [
            'OpenAI — compte sans crédit (429 insufficient_quota)' => ['openai', 'OpenAI', 429,
                '{"error":{"message":"You exceeded your current quota, please check your plan and billing details. For more information on this error, read the docs: https://platform.openai.com/docs/guides/error-codes/api-errors.","type":"insufficient_quota","param":null,"code":"insufficient_quota"}}'],
            'OpenAI — facturation inactive (429)' => ['openai', 'OpenAI', 429,
                '{"error":{"message":"Your account is not active, please check your billing details on our website.","type":"billing_not_active","param":null,"code":"billing_not_active"}}'],
            'OpenAI — plafond de facturation (400)' => ['openai', 'OpenAI', 400,
                '{"error":{"message":"Billing hard limit has been reached","type":"invalid_request_error","param":null,"code":"billing_hard_limit_reached"}}'],
            // Codes du guide « Error codes » d'OpenAI (2026-10) ; corps au
            // format d'erreur documenté, message tiré de la colonne « Cause ».
            'OpenAI — crédit prépayé épuisé (429 credit_balance_exhausted)' => ['openai', 'OpenAI', 429,
                '{"error":{"message":"Your organization has no prepaid credits remaining.","type":"requests","param":null,"code":"credit_balance_exhausted"}}'],
            'OpenAI — plafond mensuel du palier (429 organization_usage_limit_exceeded)' => ['openai', 'OpenAI', 429,
                '{"error":{"message":"Your organization reached its OpenAI-assigned monthly usage limit.","type":"requests","param":null,"code":"organization_usage_limit_exceeded"}}'],
            'Anthropic — solde trop bas (400)' => ['anthropic', 'Anthropic', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits."},"request_id":"req_011"}'],
            'Anthropic — facturation (402 billing_error)' => ['anthropic', 'Anthropic', 402,
                '{"type":"error","error":{"type":"billing_error","message":"There is an issue with your billing or payment information."},"request_id":"req_012"}'],
            // Documenté : même type et même statut qu'une limite de débit,
            // seul `details.error_code` les sépare.
            'Anthropic — plafond de dépense mensuel (429)' => ['anthropic', 'Anthropic', 429,
                '{"type":"error","error":{"type":"rate_limit_error","message":"You have reached your API usage limits: your organization has crossed its monthly API usage threshold, set based on your organization\'s API tier. You will regain access on 2026-11-01 at 00:00 UTC.","details":{"error_code":"enforced_spend_limit_reached"}},"request_id":"req_018"}'],
            'Anthropic — plafond posé par l\'organisation (400)' => ['anthropic', 'Anthropic', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"You have reached your specified workspace API usage limits. You will regain access on 2026-11-01 at 00:00 UTC."},"request_id":"req_019"}'],
            'Mistral — paiement requis (402, forme supposée)' => ['mistral', 'Mistral', 402,
                '{"object":"error","message":"Payment required: no active payment method or insufficient credits for this workspace.","type":"invalid_request_error","param":null,"code":null}'],
            'Mistral — plafond mensuel (429, forme supposée)' => ['mistral', 'Mistral', 429,
                '{"object":"error","message":"Monthly usage limit exceeded for this workspace: tokens per month limit reached.","type":"rate_limited","param":null,"code":"1300"}'],
        ];
    }

    #[DataProvider('refusFauteDeCredit')]
    public function testUnRefusFauteDeCreditEstReconnu(string $provider, string $libelle, int $statut, string $corps): void
    {
        $detail = LlmAdapterHelpers::errorDetail(json_decode($corps, true), $corps, $statut);
        $desc   = LlmErrorPresenter::parse(LlmAdapterHelpers::buildHttpErrorMessage($libelle, $statut, $detail));

        $this->assertSame('quota', $desc['kind'] ?? null, $detail);
        $p = LlmErrorPresenter::present($desc);
        $this->assertSame('Crédit épuisé chez ' . $libelle, $p['title']);
        $this->assertTrue($p['contact_admin']);
        $this->assertFalse($p['retryable'], 'Attendre ne rechargera pas le compte.');
        $this->assertArrayNotHasKey('retry_after', $desc, 'Aucun délai promis : l\'accès revient quand le compte est rechargé.');
        // Pas un service « saturé » : le LLM de secours prend quand même le relais.
        $this->assertTrue(LlmErrorPresenter::warrantsFailover($desc));
    }

    public function testLaLimiteDeDebitResteUneLimiteDeDebit(): void
    {
        // Même type qu'un plafond de dépense, sans `enforced_spend_limit_reached`.
        $corps  = '{"type":"error","error":{"type":"rate_limit_error","message":"This request would exceed your organization\'s rate limit of 30,000 input tokens per minute."}}';
        $detail = LlmAdapterHelpers::errorDetail(json_decode($corps, true), $corps, 429);
        $this->assertSame('rate_limit', LlmErrorPresenter::classify(429, $detail));

        $mistral = '{"object":"error","message":"Rate limit exceeded","type":"rate_limited","param":null,"code":"1300"}';
        $this->assertSame('rate_limit', LlmErrorPresenter::classify(429, LlmAdapterHelpers::errorDetail(json_decode($mistral, true), $mistral, 429)));
    }

    public function testLeCodeDuPlafondDAnthropicEstLuDansSesDetails(): void
    {
        $detail = LlmAdapterHelpers::errorDetail([
            'type'  => 'error',
            'error' => ['type' => 'rate_limit_error', 'message' => 'm', 'details' => ['error_code' => 'enforced_spend_limit_reached']],
        ], '', 429);
        $this->assertStringContainsString('enforced_spend_limit_reached', $detail);
    }

    // ------------------------------------------------------------------
    // 2. Par les vrais adaptateurs, sur un faux fournisseur HTTP
    // ------------------------------------------------------------------

    /** @return array<string, array{string, string, string, string, bool, bool}> */
    public static function parcoursAdaptateurs(): array
    {
        $cas = [];
        foreach ([
            ['openai', OpenAIAdapter::class, 'o3', 'OpenAI', 'openai-quota'],             // Chat Completions
            ['openai', OpenAIAdapter::class, 'gpt-5.6', 'OpenAI', 'openai-quota'],        // Responses API
            ['openai', OpenAIAdapter::class, 'gpt-5.6', 'OpenAI', 'openai-failed-event'], // 200 puis response.failed
            ['anthropic', AnthropicAdapter::class, 'claude-opus-5', 'Anthropic', 'anthropic-credit'],
            ['anthropic', AnthropicAdapter::class, 'claude-opus-5', 'Anthropic', 'anthropic-spendcap'],
            ['mistral', MistralAdapter::class, 'mistral-large-latest', 'Mistral', 'mistral-402'],
        ] as [$p, $classe, $modele, $libelle, $route]) {
            foreach (['message' => [false, false], 'recherche Internet' => [true, false], 'outils' => [false, true]] as $mode => [$web, $outils]) {
                // Le flux de la Responses API sert les deux chemins d'OpenAI
                // (la recherche Internet y bascule) : inutile de tout rejouer.
                if ($route === 'openai-failed-event' && $mode === 'recherche Internet') {
                    continue;
                }
                $cas["$libelle $modele / $route / $mode"] = [$p, $classe, $modele, $route, $web, $outils];
            }
        }
        return $cas;
    }

    #[DataProvider('parcoursAdaptateurs')]
    public function testLeVraiAdaptateurRendUneErreurDeCredit(string $provider, string $classe, string $modele, string $route, bool $web, bool $outils): void
    {
        $base = self::fauxFournisseur();
        $catalogue = require ROOT . '/config/llm_catalog.php';
        foreach ($catalogue[$provider]['endpoints'] as $nom => $_) {
            $catalogue[$provider]['endpoints'][$nom] = $base . '/' . $route;
        }
        (new ReflectionProperty(LlmCatalog::class, 'cache'))->setValue(null, $catalogue);

        $adapter = (new ReflectionClass($classe))->newInstanceWithoutConstructor();
        (new ReflectionProperty($classe, 'apiKey'))->setValue($adapter, 'cle-factice');
        (new ReflectionProperty($classe, 'model'))->setValue($adapter, $modele);

        $chunks = [];
        $cb = static function (string $c) use (&$chunks): void { $chunks[] = $c; };
        $journal = ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            if ($outils) {
                $adapter->streamWithTools(
                    [['role' => 'user', 'content' => 'Bonjour']], 'système',
                    [['name' => 'chercher', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]],
                    $cb, static fn(): array => ['ok' => true], null, $web
                );
            } else {
                $adapter->stream([['role' => 'user', 'content' => 'Bonjour']], 'système', $cb, null, $web);
            }
        } finally {
            ini_set('error_log', $journal === false ? '' : $journal);
        }

        $this->assertNotEmpty($chunks, 'L\'adaptateur doit dire quelque chose.');
        $desc = LlmErrorPresenter::parse($chunks[0]);
        $this->assertSame('quota', $desc['kind'] ?? null, $chunks[0]);
        $p = LlmErrorPresenter::present($desc);
        $this->assertStringStartsWith('Crédit épuisé chez ', $p['title']);
        foreach (['req_0', 'platform.openai.com', 'insufficient_quota', 'credit balance'] as $brut) {
            $this->assertStringNotContainsString($brut, json_encode($p, JSON_UNESCAPED_UNICODE));
        }
    }

    // ------------------------------------------------------------------
    // 3. Un message lisible par tous, qui dit vers qui se tourner
    // ------------------------------------------------------------------

    public function testLeMessageDeCreditEstLisibleSansJargon(): void
    {
        foreach (['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'mistral' => 'Mistral', '' => null] as $provider => $libelle) {
            $p = LlmErrorPresenter::present(['kind' => 'quota', 'provider' => $provider ?: null, 'status' => 429]);
            foreach (['instance', 'quota', 'API', 'HTTP', '429', 'token', 'clé'] as $jargon) {
                $this->assertStringNotContainsString($jargon, $p['message'], "« $jargon » dans : {$p['message']}");
            }
            $this->assertStringContainsString('Prévenez un administrateur', $p['message']);
            $this->assertStringContainsString('choisissez un autre modèle', $p['message']);
            $this->assertStringContainsString("Ce n'est lié ni à votre message ni à votre compte", $p['message']);
            if ($libelle !== null) {
                $this->assertStringContainsString('chez ' . $libelle, $p['message']);
            }
        }

        I18n::use('en');
        $en = LlmErrorPresenter::present(['kind' => 'quota', 'provider' => 'anthropic', 'status' => 400]);
        $this->assertSame('Out of credit at Anthropic', $en['title']);
        $this->assertStringContainsString('Tell an administrator', $en['message']);
        $this->assertStringNotContainsString('crédit', $en['message']);
    }

    public function testChaqueCauseAReglerParUnAdministrateurLeDit(): void
    {
        foreach (LlmErrorPresenter::KINDS as $kind) {
            $p = LlmErrorPresenter::present(['kind' => $kind, 'provider' => 'openai', 'status' => null]);
            $doitPrevenir = in_array($kind, LlmErrorPresenter::CONTACT_KINDS, true);
            $this->assertSame($doitPrevenir, $p['contact_admin'], $kind);
            if ($doitPrevenir) {
                $this->assertMatchesRegularExpression('/[Pp]révenez un administrateur/u', $p['message'], $kind);
                I18n::use('en');
                $this->assertStringContainsString('administrator', LlmErrorPresenter::present(['kind' => $kind, 'provider' => 'openai', 'status' => null])['message'], $kind);
                I18n::use('fr');
            }
        }
        // Ce que l'utilisateur règle lui-même ne l'envoie pas déranger un administrateur.
        foreach (['context', 'attachment', 'filtered', 'truncated', 'refusal', 'timeout'] as $kind) {
            $this->assertNotContains($kind, LlmErrorPresenter::CONTACT_KINDS);
        }
        // Chaque lien de réglage vise une page d'administration et porte un libellé traduit côté client.
        $client = require ROOT . '/lang/en.client.php';
        foreach (LlmErrorPresenter::ADMIN_FIX as $kind => $fix) {
            $this->assertContains($kind, LlmErrorPresenter::CONTACT_KINDS, $kind);
            $this->assertStringStartsWith('/admin/', $fix['url']);
            $this->assertArrayHasKey($fix['label'], $client, $fix['label']);
        }
    }

    // ------------------------------------------------------------------
    // 4. L'administration est avertie, et le constat s'éteint
    // ------------------------------------------------------------------

    public function testUnRefusAvantLePremierMotEstConsignePourLAdministration(): void
    {
        $emis = [];
        ChatController::setEmitSink(static function (array $e) use (&$emis): void { $emis[] = $e; });
        try {
            $controleur = (new ReflectionClass(ChatController::class))->newInstanceWithoutConstructor();
            $m = new ReflectionMethod(ChatController::class, 'filterLlmChunk');

            // Crédit épuisé : part au LLM de secours… et reste consigné.
            $commence = false; $erreur = null; $interruption = null;
            try {
                $m->invokeArgs($controleur, ['[Erreur OpenAI (429) : insufficient_quota — You exceeded your current quota]', &$commence, &$erreur, &$interruption]);
                $this->fail('La bascule sur le LLM de secours est attendue.');
            } catch (LlmRetryableException) {
            }
            $actifs = ProviderIncident::active();
            $this->assertSame('quota', $actifs['openai']['kind'] ?? null);

            // Clé refusée (pas de secours) : bloc d'erreur ET constat.
            $commence = false; $erreur = null; $interruption = null;
            $m->invokeArgs($controleur, ['[Erreur Anthropic (401) : authentication_error — invalid x-api-key]', &$commence, &$erreur, &$interruption]);
            $this->assertSame('auth', $erreur['kind'] ?? null);
            $this->assertTrue($erreur['contact_admin']);
            $this->assertSame('auth', ProviderIncident::active()['anthropic']['kind'] ?? null);

            // Une surcharge passe d'elle-même : rien à régler pour l'administration.
            $commence = false; $erreur = null; $interruption = null;
            $m->invokeArgs($controleur, ['[Erreur Mistral (400) : context_length_exceeded — too long]', &$commence, &$erreur, &$interruption]);
            $this->assertArrayNotHasKey('mistral', ProviderIncident::active());
        } finally {
            ChatController::setEmitSink(null);
        }
    }

    public function testLeConstatSeCumuleEtSEteint(): void
    {
        $t0 = 1_800_000_000;
        ProviderIncident::note(['kind' => 'quota', 'provider' => 'OpenAI'], $t0);
        ProviderIncident::note(['kind' => 'quota', 'provider' => 'openai'], $t0 + 60);
        ProviderIncident::note(['kind' => 'quota', 'provider' => 'Kimi (Moonshot)'], $t0 + 60);
        ProviderIncident::note(['kind' => 'rate_limit', 'provider' => 'openai'], $t0 + 90);
        ProviderIncident::note(['kind' => 'quota', 'provider' => 'LLM local'], $t0 + 90);

        $actifs = ProviderIncident::active($t0 + 120);
        $this->assertSame(['moonshot', 'openai'], array_keys($actifs), 'Libellés des messages ramenés aux slugs ; ni débit, ni modèle local.');
        $this->assertSame(['kind' => 'quota', 'first' => $t0, 'last' => $t0 + 60, 'count' => 2], $actifs['openai']);

        // Autre cause, autre remède : le compteur repart.
        ProviderIncident::note(['kind' => 'auth', 'provider' => 'openai'], $t0 + 200);
        $this->assertSame(1, ProviderIncident::active($t0 + 200)['openai']['count']);

        // Le compte répond de nouveau : éteint.
        ProviderIncident::resolve('openai');
        $this->assertArrayNotHasKey('openai', ProviderIncident::active($t0 + 200));
        // Sans récidive pendant trois jours : éteint aussi.
        $this->assertSame([], ProviderIncident::active($t0 + 60 + ProviderIncident::TTL_SEC + 1));

        // Formes illisibles ignorées.
        $this->assertSame([], ProviderIncident::prune(['x' => 'y', 'openai' => ['kind' => 'quota']], $t0));
        $this->assertSame([], ProviderIncident::decode('{pas du json'));
    }

    public function testLeConstatDevientUnPointARegler(): void
    {
        $now = 1_800_000_000;
        $signaux = AdminAttention::providerIncidentSignals([
            'openai'    => ['kind' => 'quota', 'first' => $now - 7200, 'last' => $now - 60, 'count' => 14],
            'anthropic' => ['kind' => 'auth', 'first' => $now - 300, 'last' => $now - 300, 'count' => 1],
        ], $now);

        $this->assertCount(2, $signaux);
        foreach ($signaux as $s) {
            $this->assertSame('llms-keys', $s['entry'], 'Sur Modèles IA › Clés API, là où l\'on vérifie le compte.');
            $this->assertSame(AdminAttention::ALERT, $s['level'], 'Les utilisateurs voient des refus : alerte, pas avertissement.');
            $this->assertStringContainsString('s\'éteint', $s['text']);
        }
        $this->assertSame('crédit épuisé chez OpenAI', $signaux[0]['short']);
        $this->assertStringContainsString('14 demandes refusées', $signaux[0]['text']);
        $this->assertStringContainsString('depuis 2 heures', $signaux[0]['text']);
        $this->assertStringContainsString('Rechargez-le chez OpenAI', $signaux[0]['text']);
        $this->assertStringContainsString('1 demande refusée', $signaux[1]['text']);
        $this->assertStringContainsString('Enregistrez une nouvelle clé', $signaux[1]['text']);

        $entree = AdminAttention::forEntry($signaux, 'llms-keys');
        $this->assertSame(AdminAttention::ALERT, $entree['level']);
    }

    public function testLeConstatEstPoseEtLeveAuxBonsEndroits(): void
    {
        $chat  = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $file  = (string)file_get_contents(ROOT . '/src/Jobs/JobQueue.php');
        $meter = (string)file_get_contents(ROOT . '/src/Billing/UsageMeter.php');
        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $voix  = (string)file_get_contents(ROOT . '/src/Helpers/SpeechSynthesizer.php');
        $att   = (string)file_get_contents(ROOT . '/src/Helpers/AdminAttention.php');

        $this->assertStringContainsString('ProviderIncident::note($desc)', $chat, 'Chat, avant le premier mot.');
        $this->assertStringContainsString('ProviderIncident::note($broken)', $chat, 'Chat, réponse coupée en route.');
        $this->assertStringContainsString('ProviderIncident::note($cause)', $chat, 'Session de dictée.');
        $this->assertStringContainsString('ProviderIncident::note($error)', $file, 'Image, page web, présentation.');
        $this->assertStringContainsString('ProviderIncident::note($cause)', $voix, 'Lecture à voix haute.');
        // Le constat précède la bascule sur le secours : elle ne doit pas masquer la panne.
        $corps = substr($chat, (int)strpos($chat, 'private function filterLlmChunk'), 1500);
        $this->assertLessThan(strpos($corps, 'throw new LlmRetryableException'), strpos($corps, 'ProviderIncident::note($desc)'));

        $this->assertStringContainsString('ProviderIncident::resolve(', $meter, 'Opération facturée = compte qui répond.');
        $this->assertSame(2, substr_count($admin, 'ProviderIncident::resolve($provider)'), 'Clé enregistrée, test réussi.');
        $this->assertStringContainsString('self::providerIncidentSignals(ProviderIncident::active(), time())', $att);

        foreach ([ROOT . '/config/classes.php', ROOT . '/tests/bootstrap.php'] as $liste) {
            $code = (string)file_get_contents($liste);
            foreach (['ProviderIncident.php', 'AdminContacts.php', 'SpeechSynthesisException.php'] as $classe) {
                $this->assertStringContainsString($classe, $code, "$classe chargée par $liste");
            }
            $this->assertLessThan(strpos($code, 'SpeechSynthesizer.php'), strpos($code, 'SpeechSynthesisException.php'));
        }
    }

    // ------------------------------------------------------------------
    // 5. L'encadré du chat : prévenir un administrateur, nommé
    // ------------------------------------------------------------------

    public function testLesAdministrateursProposesSontReels(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Zoé Martin', 'email' => 'Zoe@Exemple.fr', 'role' => 'admin', 'is_active' => 1, 'google_id' => 'g1'],
            ['id' => 2, 'name' => 'Alex Durand', 'email' => 'alex@exemple.fr', 'role' => 'admin', 'is_active' => 1, 'google_id' => 'g2'],
            ['id' => 3, 'name' => 'Inactive', 'email' => 'off@exemple.fr', 'role' => 'admin', 'is_active' => 0, 'google_id' => 'g3'],
            ['id' => 4, 'name' => 'Créatrice', 'email' => 'crea@exemple.fr', 'role' => 'creator', 'is_active' => 1, 'google_id' => 'g4'],
            ['id' => 5, 'name' => 'Admin local', 'email' => 'admin@local', 'role' => 'admin', 'is_active' => 1, 'google_id' => 'local_admin'],
            ['id' => 6, 'name' => 'Sans domaine', 'email' => 'root@intranet', 'role' => 'admin', 'is_active' => 1, 'google_id' => 'g6'],
            ['id' => 7, 'name' => '', 'email' => 'pas-une-adresse', 'role' => 'admin', 'is_active' => 1, 'google_id' => 'g7'],
            ['id' => 8, 'name' => 'Doublon', 'email' => 'zoe@exemple.fr', 'role' => 'admin', 'is_active' => 1, 'google_id' => 'g8'],
            ['id' => 9, 'name' => '', 'email' => 'anonyme@exemple.fr', 'role' => 'admin', 'is_active' => 1, 'google_id' => 'g9'],
        ];

        $this->assertSame([
            ['name' => 'Alex Durand', 'email' => 'alex@exemple.fr'],
            ['name' => 'anonyme@exemple.fr', 'email' => 'anonyme@exemple.fr'],
            ['name' => 'Zoé Martin', 'email' => 'zoe@exemple.fr'],
        ], AdminContacts::fromRows($rows, null));

        // La personne connectée n'est pas proposée à elle-même ; bornage.
        $this->assertSame(['anonyme@exemple.fr', 'zoe@exemple.fr'], array_column(AdminContacts::fromRows($rows, 2), 'email'));
        $this->assertCount(1, AdminContacts::fromRows($rows, null, 1));
    }

    public function testLEncadreProposeDePrevenirUnAdministrateur(): void
    {
        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node introuvable.');
        }
        $chat = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $quota = LlmErrorPresenter::present(['kind' => 'quota', 'provider' => 'openai', 'status' => 429]);
        $contexte = LlmErrorPresenter::present(['kind' => 'context', 'provider' => 'openai', 'status' => 400]);

        $script = "const t = (s, v) => s.replace(/\\{(\\w+)\\}/g, (m, k) => (v && k in v) ? v[k] : m);\n"
            . "const document = { documentElement: { lang: 'fr' } };\n"
            . 'const window = { LLM_ERROR_ACTIONS: ' . json_encode(LlmErrorPresenter::clientActions(), JSON_UNESCAPED_UNICODE)
            . ", APP_NAME: 'MIA', IS_ADMIN: false, ADMIN_CONTACTS: [] };\n"
            . self::fonctionJs($chat, 'escHtml') . "\n"
            . self::fonctionJs($chat, 'escAttr') . "\n"
            . self::constanteJs($chat, 'ERROR_ICON_MAIL') . "\n"
            . self::constanteJs($chat, 'ERROR_ICON_GEAR') . "\n"
            . self::constanteJs($chat, 'ERROR_ICON_CHEVRON') . "\n"
            . self::constanteJs($chat, 'ERROR_ICON_COPY') . "\n"
            . "let errorContactSeq = 0;\n"
            . self::fonctionJs($chat, 'buildErrorBlockHtml') . "\n"
            . self::fonctionJs($chat, 'errorActionsHtml') . "\n"
            . self::fonctionJs($chat, 'errorReportText') . "\n"
            . self::fonctionJs($chat, 'fillErrorContacts') . "\n"
            . 'const quota = ' . json_encode($quota, JSON_UNESCAPED_UNICODE) . ";\n"
            . 'const contexte = ' . json_encode($contexte, JSON_UNESCAPED_UNICODE) . ";\n"
            . "const out = {};\n"
            . "out.utilisateur = buildErrorBlockHtml(quota);\n"
            . "out.contexte = buildErrorBlockHtml(contexte);\n"
            . "out.client = buildErrorBlockHtml({ kind: 'network', title: 'Connexion impossible', message: 'Réseau' });\n"
            . "const panneau = (contacts) => { window.ADMIN_CONTACTS = contacts; const p = { dataset: { errorTitle: quota.title, errorMessage: quota.message }, innerHTML: '' }; fillErrorContacts(p); return p.innerHTML; };\n"
            . "out.deux = panneau([{ name: 'Alex Durand', email: 'alex+mia@exemple.fr' }, { name: '<img src=x onerror=alert(1)>', email: 'b@exemple.fr' }]);\n"
            . "out.aucun = panneau([]);\n"
            . "window.IS_ADMIN = true;\n"
            . "out.admin = buildErrorBlockHtml(quota);\n"
            . "out.adminSurcharge = buildErrorBlockHtml({ kind: 'overloaded', title: 'Service surchargé', message: 'm', contact_admin: true });\n"
            . "process.stdout.write(JSON.stringify(out));\n";

        $r = self::executerJs($node, $script);

        // Utilisateur : le bouton, et un panneau fermé qui porte la cause.
        $this->assertStringContainsString('data-error-contact', $r['utilisateur']);
        $this->assertStringContainsString('Prévenir un administrateur', $r['utilisateur']);
        $this->assertStringContainsString('aria-expanded="false"', $r['utilisateur']);
        $this->assertMatchesRegularExpression('/class="msg-error-contacts"[^>]*hidden/', $r['utilisateur']);
        $this->assertStringContainsString('data-error-title="Crédit épuisé chez OpenAI"', $r['utilisateur']);
        // Ce que l'utilisateur règle lui-même, ou une panne vue par le seul navigateur : rien.
        $this->assertStringNotContainsString('msg-error-actions', $r['contexte']);
        $this->assertStringNotContainsString('msg-error-actions', $r['client']);

        // Le panneau : les administrateurs nommés, un courriel déjà rédigé.
        $this->assertStringContainsString('Écrivez à l\'un des administrateurs', $r['deux']);
        $this->assertStringContainsString('href="mailto:alex%2Bmia@exemple.fr?subject=', $r['deux'], 'Arobase lisible, le reste encodé.');
        preg_match('/href="mailto:alex[^"]*"/', $r['deux'], $lien);
        $corps = rawurldecode(html_entity_decode((string)($lien[0] ?? '')));
        $this->assertStringContainsString('subject=MIA — Crédit épuisé chez OpenAI', $corps);
        $this->assertStringContainsString('Bonjour Alex,', $corps);
        $this->assertStringContainsString($quota['message'], $corps);
        $this->assertStringContainsString('&lt;img', $r['deux'], 'Un nom ne s\'injecte jamais en HTML.');
        $this->assertStringNotContainsString('<img', $r['deux']);
        $this->assertStringContainsString('data-error-copy', $r['deux']);
        // Personne à nommer : la consigne générale, et la copie.
        $this->assertStringContainsString('Transmettez ce message à la personne qui gère MIA', $r['aucun']);
        $this->assertStringNotContainsString('mailto:', $r['aucun']);
        $this->assertStringContainsString('data-error-copy', $r['aucun']);

        // Administrateur : le lien de la page où régler la cause, pas de bouton.
        $this->assertStringContainsString('href="/admin/llms"', $r['admin']);
        $this->assertStringContainsString('Ouvrir les clés API', $r['admin']);
        $this->assertStringNotContainsString('data-error-contact', $r['admin']);
        $this->assertStringNotContainsString('msg-error-actions', $r['adminSurcharge'], 'Rien à régler dans l\'administration.');
    }

    public function testLEncadreEstCableDansLeChat(): void
    {
        $chat = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $tpl  = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $css  = (string)file_get_contents(ROOT . '/public/assets/css/main.css');

        foreach (['window.LLM_ERROR_ACTIONS', 'window.IS_ADMIN', 'window.ADMIN_CONTACTS', 'window.APP_NAME', 'window.LLM_ERROR_OPENAI'] as $globale) {
            $this->assertStringContainsString($globale, $tpl, $globale);
        }
        $this->assertStringContainsString('AdminContacts::forViewer(', $tpl);
        $this->assertStringContainsString('JSON_HEX_TAG', $tpl, 'Un nom ne ferme pas le <script>.');

        // Un seul gestionnaire, délégué : l'encadré est rendu à quatre endroits.
        $this->assertSame(1, substr_count($chat, "e.target.closest('[data-error-contact]')"));
        $this->assertStringContainsString("closest('[data-error-copy]')", $chat);
        // Copier ou exporter une réponse n'emporte pas ces boutons.
        $this->assertMatchesRegularExpression('/querySelectorAll\(\'[^\']*\.msg-error-actions, \.msg-error-contacts\'\)/', $chat);

        $this->assertStringContainsString('.msg-error-contacts[hidden] { display: none !important; }', $css, 'Piège `hidden` contre display.');
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\) \{\s*\.msg-error-btn \{ min-height: 44px/', $css);
        $this->assertStringContainsString('.toast-title', $css);
    }

    // ------------------------------------------------------------------
    // 6. Dictée, lecture à voix haute, image : les mêmes mots
    // ------------------------------------------------------------------

    public function testLaSessionVocaleNeRenvoiePlusLeMessageBrutDOpenAI(): void
    {
        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $corps = substr($chat, (int)strpos($chat, 'public function realtimeTranscriptionToken'), 9000);
        $this->assertStringNotContainsString('openai_body', $corps);
        $this->assertStringNotContainsString("\$decoded['error']['message']", $corps);
        $this->assertStringContainsString("LlmErrorPresenter::fromHttp(\$status, \$raw, 'openai')", $corps);

        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringNotContainsString("fail('OpenAI: '", $js, 'Le message brut d\'OpenAI partait dans une alerte.');
        $this->assertStringNotContainsString('else alert(message);', $js, 'Une panne de dictée n\'est plus une alerte du navigateur.');
        $this->assertStringNotContainsString("'SDP exchange '", substr($js, 0, (int)strpos($js, "console.warn('[mic] SDP exchange")), 'Le détail SDP reste en console.');

        // Compte sans crédit à l'ouverture d'une session : cause présentée.
        $payload = LlmErrorPresenter::fromHttp(429, '{"error":{"message":"You exceeded your current quota.","type":"insufficient_quota","code":"insufficient_quota"}}', 'openai');
        $this->assertSame('quota', $payload['kind']);
        $this->assertSame('Crédit épuisé chez OpenAI', $payload['title']);
        // Erreur vue par le seul navigateur : mêmes charges utiles que le serveur.
        $this->assertSame($payload['title'], LlmErrorPresenter::clientPayloads('openai')['quota']['title']);
    }

    public function testLaLectureAVoixHauteNommeLeCreditEpuise(): void
    {
        $transport = static fn(): array => ['status' => 429, 'body' => '{"error":{"message":"You exceeded your current quota, please check your plan and billing details.","type":"insufficient_quota","code":"insufficient_quota"}}'];
        $journal = ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            SpeechSynthesizer::synthesize('Bonjour', 'sk-test', $transport);
            $this->fail('Un 429 doit lever.');
        } catch (SpeechSynthesisException $e) {
            // Avant : « limite de débit atteinte chez OpenAI » — faux.
            $this->assertSame('quota', $e->payload()['kind']);
            $this->assertStringContainsString('Le crédit de votre organisation chez OpenAI est épuisé', $e->getMessage());
        } finally {
            ini_set('error_log', $journal === false ? '' : $journal);
        }
        $this->assertSame('quota', ProviderIncident::active()['openai']['kind'] ?? null);
    }

    public function testLOutilImagePorteLaCauseJusquALEncadre(): void
    {
        $m = new ReflectionMethod(ImageGenerator::class, 'apiError');
        $r = $m->invoke(null, ['message' => 'You exceeded your current quota.', 'code' => 'insufficient_quota', 'type' => 'insufficient_quota'], 'OpenAI', 429);
        $this->assertSame('quota', $r['error_payload']['kind'] ?? null);
        $this->assertSame('Crédit épuisé chez OpenAI', $r['error_payload']['title']);
        $this->assertSame($r['error_payload']['message'], $r['error']);

        // Paramètre refusé : message du fournisseur, aucune charge utile nommée.
        $taille = $m->invoke(null, ['message' => "Invalid value: '1024x2048'.", 'code' => 'invalid_value'], 'OpenAI', 400);
        $this->assertArrayNotHasKey('error_payload', $taille);

        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString("is_array(\$result['error_payload'] ?? null) ? \$result['error_payload']", $chat);
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    private static ?string $base = null;
    /** @var resource|null */
    private static $serveur = null;

    /** Faux fournisseur : `php -S` sur un routeur qui rend statut + corps selon le chemin. */
    private static function fauxFournisseur(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $dir = sys_get_temp_dir() . '/mia-credit-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $routes = [
            'openai-quota'       => [429, self::refusFauteDeCredit()['OpenAI — compte sans crédit (429 insufficient_quota)'][3], 'application/json'],
            'anthropic-credit'   => [400, self::refusFauteDeCredit()['Anthropic — solde trop bas (400)'][3], 'application/json'],
            'anthropic-spendcap' => [429, self::refusFauteDeCredit()['Anthropic — plafond de dépense mensuel (429)'][3], 'application/json'],
            'mistral-402'        => [402, self::refusFauteDeCredit()['Mistral — paiement requis (402, forme supposée)'][3], 'application/json'],
            'openai-failed-event' => [200,
                "event: response.created\ndata: {\"type\":\"response.created\",\"response\":{\"id\":\"r1\",\"status\":\"in_progress\"}}\n\n"
                . "event: response.failed\ndata: {\"type\":\"response.failed\",\"response\":{\"id\":\"r1\",\"status\":\"failed\",\"error\":{\"code\":\"insufficient_quota\",\"message\":\"You exceeded your current quota, please check your plan and billing details.\"}}}\n\n",
                'text/event-stream'],
        ];
        file_put_contents($dir . '/routes.json', json_encode($routes));
        file_put_contents($dir . '/router.php', '<?php
$routes = json_decode(file_get_contents(__DIR__ . "/routes.json"), true);
$cle = trim((string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
[$statut, $corps, $type] = $routes[$cle] ?? [404, "{}", "application/json"];
http_response_code($statut);
header("Content-Type: " . $type);
echo $corps;
');
        $port = 0;
        for ($essai = 0; $essai < 5 && self::$serveur === null; $essai++) {
            $port = random_int(20000, 39000);
            $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubes);
            if (!is_resource($proc)) {
                continue;
            }
            for ($i = 0; $i < 50; $i++) {
                $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($s) {
                    fclose($s);
                    self::$serveur = $proc;
                    break;
                }
                usleep(50_000);
            }
            if (self::$serveur === null) {
                proc_terminate($proc);
            }
        }
        if (self::$serveur === null) {
            self::markTestSkipped('Impossible de démarrer le faux fournisseur (php -S).');
        }
        register_shutdown_function(static function (): void {
            if (is_resource(self::$serveur)) {
                proc_terminate(self::$serveur);
            }
        });
        return self::$base = 'http://127.0.0.1:' . $port;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serveur)) {
            proc_terminate(self::$serveur);
        }
        self::$serveur = null;
        self::$base = null;
    }

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

    /** Une constante d'une ligne (`const NOM = '…';`). */
    private static function constanteJs(string $source, string $nom): string
    {
        $trouve = preg_match('/^const ' . preg_quote($nom, '/') . ' = .*;$/m', $source, $m);
        self::assertSame(1, $trouve, "Constante $nom introuvable dans chat.js.");
        return $m[0];
    }

    /** @return array<string, mixed> */
    private static function executerJs(string $node, string $script): array
    {
        $fichier = tempnam(sys_get_temp_dir(), 'mia-credit-js-');
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

    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            $candidat = rtrim($dir, '/') . '/node';
            if ($dir !== '' && is_executable($candidat)) {
                return $candidat;
            }
        }
        return null;
    }
}
