<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * « Claude dans Office » : l'add-in Office d'Anthropic branché sur MIA comme
 * passerelle. Formes pures (jeton Microsoft vérifié avec une VRAIE clé RSA,
 * relevé du flux SSE, manifeste) et câblage.
 */
final class OfficeAddinTest extends TestCase
{
    private const TENANT = '11111111-2222-4333-8444-555555555555';
    private const CLIENT = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private static ?\OpenSSLAsymmetricKey $key = null;
    private static string $pem = '';

    public static function setUpBeforeClass(): void
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($k);
        self::$key = $k;
        self::$pem = (string)openssl_pkey_get_details($k)['key'];
    }

    private static function racine(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function lire(string $chemin): string
    {
        $abs = self::racine() . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    /** Jeton signé RS256 par la clé de test (ou par une autre clé). */
    private static function jwt(array $claims, array $header = [], ?\OpenSSLAsymmetricKey $key = null): string
    {
        $h = EntraJwt::base64UrlEncode((string)json_encode($header + ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'k1']));
        $p = EntraJwt::base64UrlEncode((string)json_encode($claims));
        openssl_sign($h . '.' . $p, $sig, $key ?? self::$key, OPENSSL_ALGO_SHA256);
        return $h . '.' . $p . '.' . EntraJwt::base64UrlEncode($sig);
    }

    private static function claims(array $over = []): array
    {
        $now = 1_800_000_000;
        return $over + [
            'iss' => 'https://login.microsoftonline.com/' . self::TENANT . '/v2.0',
            'tid' => self::TENANT,
            'aud' => self::CLIENT,
            'scp' => 'access_as_user',
            'oid' => '99999999-8888-4777-8666-555555555555',
            'preferred_username' => 'Marie.Dupont@Client.fr',
            'iat' => $now - 60,
            'nbf' => $now - 60,
            'exp' => $now + 3600,
        ];
    }

    private static function verify(string $jwt, ?callable $keyFor = null): array
    {
        $keyFor ??= static fn(string $kid) => $kid === 'k1' ? openssl_pkey_get_public(self::$pem) : null;
        return EntraJwt::verify($jwt, ['tenant' => self::TENANT, 'client_id' => self::CLIENT, 'scope' => 'access_as_user'], $keyFor, 1_800_000_000);
    }

    private static function refus(string $jwt): string
    {
        try {
            self::verify($jwt);
        } catch (EntraJwtException $e) {
            return $e->reason;
        }
        return 'accepté';
    }

    // ------------------------------------------------------------------
    // Jeton Microsoft
    // ------------------------------------------------------------------

    public function testUnJetonBienSigneEstAccepte(): void
    {
        $claims = self::verify(self::jwt(self::claims()));
        $this->assertSame(self::TENANT . '.99999999-8888-4777-8666-555555555555', EntraJwt::subjectFrom($claims));
        $this->assertSame('marie.dupont@client.fr', EntraJwt::emailFrom($claims));

        // Audience écrite en URI d'application (jeton v1), émetteur v1 : acceptés.
        $this->assertNotEmpty(self::verify(self::jwt(self::claims([
            'aud' => 'api://' . self::CLIENT,
            'iss' => 'https://sts.windows.net/' . self::TENANT . '/',
        ]))));
        // Plusieurs permissions : la nôtre parmi elles suffit.
        $this->assertNotEmpty(self::verify(self::jwt(self::claims(['scp' => 'User.Read access_as_user']))));
    }

    public function testChaqueFauteDuJetonEstRefuseeAvecSaRaison(): void
    {
        $autre = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertSame('signature', self::refus(self::jwt(self::claims(), [], $autre)), 'Une autre clé ne passe pas.');
        $this->assertSame('unknown_key', self::refus(self::jwt(self::claims(), ['kid' => 'inconnue'])));
        $this->assertSame('issuer', self::refus(self::jwt(self::claims(['iss' => 'https://login.microsoftonline.com/22222222-2222-4333-8444-555555555555/v2.0']))));
        $this->assertSame('issuer', self::refus(self::jwt(self::claims(['tid' => '22222222-2222-4333-8444-555555555555']))), 'Un autre annuaire est refusé.');
        $this->assertSame('audience', self::refus(self::jwt(self::claims(['aud' => 'c2995f31-11e7-4882-b7a7-ef9def0a0266']))), 'Jeton émis pour une autre application.');
        $this->assertSame('scope', self::refus(self::jwt(self::claims(['scp' => 'User.Read']))));
        $this->assertSame('scope', self::refus(self::jwt(array_diff_key(self::claims(), ['scp' => 1]))), 'Jeton d’application (sans scp) refusé.');
        $this->assertSame('expired', self::refus(self::jwt(self::claims(['exp' => 1_800_000_000 - EntraJwt::LEEWAY_SEC - 1]))));
        $this->assertSame('not_yet_valid', self::refus(self::jwt(self::claims(['nbf' => 1_800_000_000 + EntraJwt::LEEWAY_SEC + 1]))));
        $this->assertSame('subject', self::refus(self::jwt(array_diff_key(self::claims(), ['oid' => 1]))));

        // Algorithmes : jamais « none », jamais un secret partagé.
        $p = EntraJwt::base64UrlEncode((string)json_encode(self::claims()));
        $none = EntraJwt::base64UrlEncode('{"alg":"none","kid":"k1"}') . '.' . $p . '.' . EntraJwt::base64UrlEncode('x');
        $this->assertSame('algorithm', self::refus($none));
        $hs = EntraJwt::base64UrlEncode('{"alg":"HS256","kid":"k1"}') . '.' . $p . '.' . EntraJwt::base64UrlEncode(hash_hmac('sha256', 'x', 'y', true));
        $this->assertSame('algorithm', self::refus($hs));

        // Charge modifiée après signature.
        [$h, , $s] = explode('.', self::jwt(self::claims()));
        $forge = $h . '.' . EntraJwt::base64UrlEncode((string)json_encode(self::claims(['oid' => '11111111-1111-4111-8111-111111111111']))) . '.' . $s;
        $this->assertSame('signature', self::refus($forge));

        $this->assertSame('malformed', self::refus('pas.un.jeton!'));
        $this->assertFalse(EntraJwt::looksLikeJwt('mia_office_abc'));
    }

    public function testUnAnnuaireNonGuidNeVerifieRien(): void
    {
        $this->expectException(EntraJwtException::class);
        EntraJwt::verify(self::jwt(self::claims()), ['tenant' => 'contoso.onmicrosoft.com', 'client_id' => self::CLIENT, 'scope' => 'access_as_user'],
            static fn() => openssl_pkey_get_public(self::$pem), 1_800_000_000);
    }

    public function testLaCleRsaSeReconstruitDepuisLeJwks(): void
    {
        $d = openssl_pkey_get_details(self::$key)['rsa'];
        $jwks = (string)json_encode(['keys' => [
            ['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k1', 'n' => EntraJwt::base64UrlEncode($d['n']), 'e' => EntraJwt::base64UrlEncode($d['e'])],
            ['kty' => 'RSA', 'use' => 'enc', 'kid' => 'chiffrement', 'n' => 'AQAB', 'e' => 'AQAB'],
            ['kty' => 'EC', 'kid' => 'ec'],
        ]]);
        $keys = EntraJwt::keysFromJwks($jwks);
        $this->assertSame(['k1'], array_keys($keys), 'Seules les clés RSA de signature sont gardées.');
        $claims = self::verify(self::jwt(self::claims()), static fn(string $kid) => isset($keys[$kid]) ? openssl_pkey_get_public($keys[$kid]) : null);
        $this->assertSame(self::TENANT, $claims['tid']);
        $this->assertNull(EntraJwt::keysFromJwks('{"pas":"de clés"}'));
    }

    public function testLesClesSontGardeesEtRelecturesBornees(): void
    {
        $dir = sys_get_temp_dir() . '/mia-jwks-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $d = openssl_pkey_get_details(self::$key)['rsa'];
        $jwks = (string)json_encode(['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k1', 'n' => EntraJwt::base64UrlEncode($d['n']), 'e' => EntraJwt::base64UrlEncode($d['e'])]]]);
        $appels = [];
        EntraJwt::overrideCacheDir($dir);
        EntraJwt::overrideFetcher(static function (string $url) use (&$appels, $jwks): string {
            $appels[] = $url;
            return $jwks;
        });
        try {
            $now = 1_800_000_000;
            $this->assertNotNull(EntraJwt::keyFor(self::TENANT, 'k1', $now));
            $this->assertNotNull(EntraJwt::keyFor(self::TENANT, 'k1', $now + 60));
            $this->assertCount(1, $appels, 'Clé connue : lue sur disque, Microsoft n’est pas rappelé.');
            $this->assertSame('https://login.microsoftonline.com/' . self::TENANT . '/discovery/v2.0/keys', $appels[0]);

            // Un kid inventé ne fait pas appeler Microsoft à chaque requête.
            $this->assertNull(EntraJwt::keyFor(self::TENANT, 'invente', $now + 120));
            $this->assertNull(EntraJwt::keyFor(self::TENANT, 'invente', $now + 180));
            $this->assertCount(1, $appels);
            $this->assertNull(EntraJwt::keyFor(self::TENANT, 'invente', $now + EntraJwt::JWKS_REFRESH_MIN_SEC + 1));
            $this->assertCount(2, $appels, 'Au-delà du délai, une relecture (rotation des clés).');

            // Cache périmé : relu ; Microsoft injoignable : les clés connues servent.
            EntraJwt::overrideFetcher(static fn(string $url): ?string => null);
            $this->assertNotNull(EntraJwt::keyFor(self::TENANT, 'k1', $now + EntraJwt::JWKS_TTL_SEC + 10));
            $this->assertNull(EntraJwt::keyFor('pas-un-guid', 'k1', $now));
        } finally {
            EntraJwt::overrideFetcher(null);
            EntraJwt::overrideCacheDir(null);
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    // ------------------------------------------------------------------
    // Passerelle : formes pures
    // ------------------------------------------------------------------

    public function testLesErreursOntLaFormeDAnthropicEtLeCorsToujours(): void
    {
        $e = json_decode(OfficeGateway::errorBody(403, 'Pas pour vous.'), true);
        $this->assertSame(['type' => 'error', 'error' => ['type' => 'permission_error', 'message' => 'Pas pour vous.']], $e);
        $this->assertSame('authentication_error', json_decode(OfficeGateway::errorBody(401, 'x'), true)['error']['type']);
        $this->assertSame('api_error', json_decode(OfficeGateway::errorBody(502, 'x'), true)['error']['type']);

        $cors = OfficeGateway::corsHeaders('X-Stainless-OS, anthropic-dangerous-direct-browser-access, bad header!, Authorization');
        $this->assertSame('https://pivot.claude.ai', $cors['Access-Control-Allow-Origin'], 'Seule l’origine de l’add-in.');
        $permis = array_map('trim', explode(',', $cors['Access-Control-Allow-Headers']));
        foreach (['authorization', 'x-api-key', 'content-type', 'anthropic-version', 'anthropic-beta', 'x-stainless-os', 'anthropic-dangerous-direct-browser-access'] as $h) {
            $this->assertContains($h, $permis);
        }
        $this->assertNotContains('bad header!', $permis);
        $this->assertNotContains('*', $permis, '« * » ne couvre pas Authorization.');
    }

    public function testLIdentifiantSeLitDansLesDeuxEnTetes(): void
    {
        $this->assertSame('abc', OfficeGateway::credentialFrom(['authorization' => 'Bearer abc']));
        $this->assertSame('abc', OfficeGateway::credentialFrom(['authorization' => 'bearer   abc']));
        $this->assertSame('mia_office_k', OfficeGateway::credentialFrom(['x-api-key' => ' mia_office_k ']));
        $this->assertSame('', OfficeGateway::credentialFrom(['authorization' => 'Basic dXNlcg==']));
        $this->assertSame('', OfficeGateway::credentialFrom([]));
    }

    public function testSeulsLesModelesClaudePermisSontProposes(): void
    {
        $models = OfficeGateway::claudeModels([
            'openai::gpt-6-luna'         => 'GPT-6 Luna',
            'anthropic::claude-opus-5'   => 'Claude Opus 5',
            'anthropic::claude-sonnet-5' => 'Claude Sonnet 5',
            'local'                      => 'LLM local',
        ], 'claude-opus-5');
        $this->assertSame(['claude-opus-5' => 'Claude Opus 5', 'claude-sonnet-5' => 'Claude Sonnet 5'], $models);
        // Aucun modèle activé : le fournisseur seul vaut le modèle de sa clé.
        $this->assertSame(['claude-opus-5' => 'Claude (Anthropic)'], OfficeGateway::claudeModels(['anthropic' => 'Claude (Anthropic)'], 'claude-opus-5'));

        $p = OfficeGateway::modelsPayload($models);
        $this->assertFalse($p['has_more']);
        $this->assertSame('claude-opus-5', $p['first_id']);
        $this->assertSame('claude-sonnet-5', $p['last_id']);
        $this->assertSame(['type', 'id', 'display_name', 'created_at'], array_keys($p['data'][0]));
        $this->assertGreaterThan($p['data'][1]['created_at'], $p['data'][0]['created_at'], 'Le plus récent d’abord.');
        $this->assertNull(OfficeGateway::modelsPayload([])['first_id']);
    }

    public function testAnthropicNeRecoitQueLaCleDeLInstance(): void
    {
        $h = OfficeGateway::upstreamHeaders([
            'authorization'     => 'Bearer jeton-entra',
            'x-api-key'         => 'mia_office_perso',
            'anthropic-version' => '2023-06-01',
            'anthropic-beta'    => 'files-api-2025-04-14, code-execution-2025-08-25',
            'x-stainless-os'    => 'MacOS',
            'accept'            => 'text/event-stream',
        ], 'sk-ant-instance', '2023-06-01');
        $joint = implode("\n", $h);
        $this->assertContains('x-api-key: sk-ant-instance', $h);
        $this->assertContains('anthropic-beta: files-api-2025-04-14,code-execution-2025-08-25', $h);
        $this->assertContains('Accept: text/event-stream', $h);
        $this->assertStringNotContainsString('jeton-entra', $joint);
        $this->assertStringNotContainsString('mia_office_perso', $joint);
        $this->assertStringNotContainsString('stainless', $joint);

        $h2 = OfficeGateway::upstreamHeaders(['anthropic-version' => "x\r\nInjection: 1", 'anthropic-beta' => "a\r\nb"], 'k', '2023-06-01');
        $this->assertContains('anthropic-version: 2023-06-01', $h2);
        $this->assertStringNotContainsString('Injection', implode("\n", $h2));
        $this->assertSame([], preg_grep('/^anthropic-beta/', $h2));
    }

    public function testLeFluxEstReleveSansRienChanger(): void
    {
        $flux = "event: message_start\n"
            . 'data: {"type":"message_start","message":{"id":"msg_01ABC","model":"claude-opus-5","usage":{"input_tokens":1200,"cache_read_input_tokens":800,"cache_creation_input_tokens":0,"output_tokens":1}}}' . "\n\n"
            . "event: content_block_delta\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Bonjour"}}' . "\n\n"
            . "event: ping\ndata: {\"type\":\"ping\"}\n\n"
            . "event: message_delta\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"input_tokens":3400,"output_tokens":250,"server_tool_use":{"web_search_requests":2}}}' . "\n\n"
            . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

        // Coupé n'importe où : le relevé est le même.
        foreach ([1, 7, 64, strlen($flux)] as $taille) {
            $state = OfficeGateway::usageState();
            foreach (str_split($flux, $taille) as $morceau) {
                $state = OfficeGateway::feedSse($state, $morceau);
            }
            $this->assertSame('msg_01ABC', $state['id']);
            $this->assertSame('Bonjour', $state['text']);
            $this->assertSame('end_turn', $state['stop']);
            $tokens = OfficeGateway::tokensFor($state, 'claude-opus-5', false, 0);
            $this->assertSame(['input' => 4200, 'input_cached' => 800, 'input_cache_write' => 0, 'input_cache_write_1h' => 0, 'output' => 250, 'web_searches' => 2], $tokens, "morceaux de $taille");
        }
    }

    /**
     * L'add-in écrit ses requêtes : ce qui change le prix chez Anthropic
     * (cache écrit pour une heure, mode rapide, inférence aux États-Unis) est
     * relevé et part avec les jetons — sinon MIA facturerait au tarif normal
     * une requête qu'Anthropic facture plus cher.
     */
    public function testLesReglagesQuiChangentLePrixSontReleves(): void
    {
        $this->assertSame(1.0, OfficeGateway::priceFactor('', ''));
        $this->assertSame(1.0, OfficeGateway::priceFactor('standard', 'global'));
        $this->assertSame(2.0, OfficeGateway::priceFactor('fast', ''));
        $this->assertSame(1.1, OfficeGateway::priceFactor('', 'us'));
        $this->assertSame(2.2, OfficeGateway::priceFactor('fast', 'us'));

        $flux = "event: message_start\n"
            . 'data: {"type":"message_start","message":{"id":"msg_01","model":"claude-opus-5-5","usage":{"input_tokens":200,"cache_read_input_tokens":0,"cache_creation_input_tokens":6000,"cache_creation":{"ephemeral_5m_input_tokens":1000,"ephemeral_1h_input_tokens":5000},"output_tokens":1,"speed":"fast","inference_geo":"us"}}}' . "\n\n"
            . "event: message_delta\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":90}}' . "\n\n";
        $state = OfficeGateway::feedSse(OfficeGateway::usageState(), $flux);
        $tokens = OfficeGateway::tokensFor($state, 'claude-opus-5-5', false, 0);
        $this->assertSame(6200, $tokens['input']);
        $this->assertSame(6000, $tokens['input_cache_write']);
        $this->assertSame(5000, $tokens['input_cache_write_1h'], 'La part écrite pour une heure est relevée.');
        $this->assertSame(2.2, $tokens['price_factor']);

        // Sans ces réglages : aucun multiplicateur, rien d'écrit pour une heure.
        $state = OfficeGateway::feedSse(OfficeGateway::usageState(), "data: {\"type\":\"message\",\"id\":\"m\",\"model\":\"claude-opus-5-5\",\"usage\":{\"input_tokens\":10,\"output_tokens\":2}}\n");
        $tokens = OfficeGateway::tokensFor($state, 'claude-opus-5-5', false, 0);
        $this->assertArrayNotHasKey('price_factor', $tokens);
        $this->assertSame(0, $tokens['input_cache_write_1h']);

        // Le compteur borne le multiplicateur : jamais une remise, jamais démesuré.
        $this->assertSame(1.0, UsageMeter::priceFactor([]));
        $this->assertSame(1.0, UsageMeter::priceFactor(['price_factor' => 0.5]));
        $this->assertSame(1.0, UsageMeter::priceFactor(['price_factor' => 'x']));
        $this->assertSame(2.2, UsageMeter::priceFactor(['price_factor' => 2.2]));
        $this->assertSame(10.0, UsageMeter::priceFactor(['price_factor' => 99]));

        // Les montants viennent de la page Pricing d'Anthropic.
        $this->assertSame(2.0, CreditService::CACHE_WRITE_1H_RATIO);
        $this->assertSame(2.0, OfficeGateway::FAST_MODE_FACTOR);
        $this->assertSame(1.1, OfficeGateway::US_INFERENCE_FACTOR);
    }

    public function testUneReponseSansFluxEtUneReponseCoupee(): void
    {
        $json = (string)json_encode(['id' => 'msg_x', 'type' => 'message', 'model' => 'claude-opus-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Total : 42']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 9]]);
        $t = OfficeGateway::tokensFor(OfficeGateway::stateFromJson($json), 'claude-opus-5', false, 0);
        $this->assertSame(100, $t['input']);
        $this->assertSame(9, $t['output']);

        // Coupée avant le dernier évènement : la sortie est estimée d'après le texte reçu.
        $state = OfficeGateway::feedSse(OfficeGateway::usageState(),
            'data: {"type":"message_start","message":{"id":"m","model":"claude-opus-5","usage":{"input_tokens":500,"output_tokens":1}}}' . "\n"
            . 'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"' . str_repeat('mot ', 200) . '"}}' . "\n");
        $cut = OfficeGateway::tokensFor($state, 'claude-opus-5', true, 9999);
        $this->assertSame(500, $cut['input'], 'Une entrée relevée n’est jamais remplacée par l’estimation.');
        $this->assertGreaterThan(1, $cut['output']);

        // Coupée avant tout relevé : l'entrée est estimée sur le TEXTE seulement.
        $body = ['model' => 'claude-opus-5', 'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => str_repeat('a', 400)],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => str_repeat('A', 400000)]],
        ]]]];
        $this->assertLessThan(200, OfficeGateway::inputEstimate($body), 'Une image en base64 ne compte pas pour des centaines de milliers de jetons.');
        $this->assertGreaterThanOrEqual(100, OfficeGateway::inputEstimate($body));
    }

    public function testLeModeleServiEstCeluiQuOnTarife(): void
    {
        $state = OfficeGateway::applyEvent(OfficeGateway::usageState(), ['type' => 'message_start', 'message' => [
            'id' => 'm', 'model' => 'claude-opus-4-8', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]]);
        $this->assertSame('claude-opus-4-8', OfficeGateway::tokensFor($state, 'claude-fable-5-1', false, 0)['model'] ?? null);
        $this->assertArrayNotHasKey('model', OfficeGateway::tokensFor($state, 'claude-opus-4-8', false, 0));
        $this->assertSame('claude-opus-5', OfficeGateway::baseModel('claude-opus-5'));
    }

    // ------------------------------------------------------------------
    // Réglages, jetons, manifeste
    // ------------------------------------------------------------------

    public function testLesParametresDuManifesteSuiventLeMode(): void
    {
        $entra = OfficeAddin::manifestParams('https://mia.client.fr/office', true, self::CLIENT, true);
        $this->assertSame([
            'gateway_url'         => 'https://mia.client.fr/office',
            'gateway_api_format'  => 'anthropic',
            'entra_sso'           => '1',
            'graph_client_id'     => self::CLIENT,
            'entra_scope'         => 'api://' . self::CLIENT . '/access_as_user',
            'gateway_auth_source' => 'entra',
        ], $entra);

        $jeton = OfficeAddin::manifestParams('https://mia.client.fr/office', false, '', false);
        $this->assertSame('authorization', $jeton['gateway_auth_header']);
        $this->assertSame('0', $jeton['auto_connect'], 'Le formulaire s’affiche : chacun colle son jeton.');
        $this->assertSame('web_search', $jeton['disabled_features']);
        $this->assertArrayNotHasKey('gateway_token', $jeton, 'Jamais un jeton dans le manifeste.');
        $this->assertArrayNotHasKey('entra_sso', $jeton);
    }

    public function testLaSaisieDeLAdministrateurEstNormalisee(): void
    {
        $ok = OfficeAddin::normalizeInput(['enabled' => '1', 'auth' => 'entra', 'client_id' => ' ' . strtoupper(self::CLIENT) . ' ', 'tenant' => self::TENANT, 'web_search' => '1', 'apps' => ['powerpoint', 'excel', 'outlook']]);
        $this->assertTrue($ok['enabled']);
        $this->assertSame('entra', $ok['auth']);
        $this->assertSame(self::CLIENT, $ok['client_id']);
        $this->assertSame(['excel', 'powerpoint'], $ok['apps'], 'Ordre du catalogue, application inconnue écartée.');
        $this->assertSame([], $ok['errors']);

        $ko = OfficeAddin::normalizeInput(['auth' => 'n’importe', 'client_id' => 'mon-appli', 'tenant' => 'contoso.onmicrosoft.com']);
        $this->assertFalse($ko['enabled']);
        $this->assertFalse($ko['web_search']);
        $this->assertSame(OfficeAddin::AUTH_BOTH, $ko['auth']);
        $this->assertSame('', $ko['client_id']);
        $this->assertSame(['excel', 'word', 'powerpoint'], $ko['apps'], 'Aucune case cochée : jamais un manifeste sans application.');
        $this->assertCount(3, $ko['errors']);
    }

    public function testLesTroisApplicationsOntLeurHoteEtLeurIcone(): void
    {
        $this->assertSame(['excel', 'word', 'powerpoint'], array_keys(OfficeAddin::APPS));
        $this->assertSame(['Workbook', 'Document', 'Presentation'], OfficeAddin::hostsFor(['powerpoint', 'word', 'excel']));
        $this->assertSame(['word'], OfficeAddin::normalizeApps(' Word ,inconnue'));
        foreach (OfficeAddin::APPS as $app => $a) {
            $svg = OfficeAddin::appIcon($app);
            $this->assertStringStartsWith('<svg class="office-app-icon"', $svg);
            $this->assertStringContainsString('aria-label="' . $a['label'] . '"', $svg);
            $this->assertStringContainsString($a['color'], $svg, 'Couleur de l’application.');
            $this->assertStringContainsString('>' . $a['letter'] . '</text>', $svg);
            $this->assertNotFalse(simplexml_load_string($svg), $app . ' : SVG bien formé.');
        }
        $this->assertSame('', OfficeAddin::appIcon('outlook'));
        $this->assertSame(3, substr_count(OfficeAddin::appIcons(), '<svg'));
        $this->assertSame(1, substr_count(OfficeAddin::appIcons(['word']), '<svg'));
        $this->assertSame('Excel, Word et PowerPoint', OfficeAddin::appsSentence(['powerpoint', 'excel', 'word']));
        $this->assertSame('Excel ou Word', OfficeAddin::appsSentence(['word', 'excel'], true));
        $this->assertSame('PowerPoint', OfficeAddin::appsSentence(['powerpoint']));
    }

    public function testLeManifesteNeGardeQueLesApplicationsChoisies(): void
    {
        $bloc = static fn(string $h): string => "      <Host xsi:type=\"{$h}\">\n        <DesktopFormFactor><GetStarted/></DesktopFormFactor>\n      </Host>\n";
        $modele = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<OfficeApp xmlns=\"http://schemas.microsoft.com/office/appforoffice/1.1\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\">\n"
            . "  <Id>4e2fd29d-dad0-40b3-af3c-a16e347a5ddc</Id>\n  <Version>1.0.0.12</Version>\n"
            . "  <Hosts>\n    <Host Name=\"Workbook\" />\n    <Host Name=\"Document\" />\n    <Host Name=\"Presentation\" />\n  </Hosts>\n"
            . "  <DefaultSettings><SourceLocation DefaultValue=\"https://pivot.claude.ai/?m=unified\" /></DefaultSettings>\n"
            . "  <VersionOverrides xsi:type=\"VersionOverridesV1_0\">\n    <Hosts>\n"
            . $bloc('Workbook') . "\n      <!-- Word (Document) -->\n" . $bloc('Document')
            . "\n      <!-- PowerPoint (Presentation) -->\n" . $bloc('Presentation')
            . "    </Hosts>\n    <Resources><bt:Urls xmlns:bt=\"x\"><bt:Url id=\"Taskpane.Url\" DefaultValue=\"https://pivot.claude.ai/?m=unified\"/></bt:Urls></Resources>\n"
            . "  </VersionOverrides>\n</OfficeApp>\n";

        $sansWord = OfficeManifest::keepHosts($modele, ['Workbook', 'Presentation']);
        $this->assertStringNotContainsString('Document', $sansWord, 'Ni la ligne, ni le bloc, ni son commentaire.');
        $this->assertSame(2, substr_count($sansWord, '<Host Name='));
        $this->assertSame(2, substr_count($sansWord, '<Host xsi:type='));
        $this->assertNotFalse(simplexml_load_string($sansWord));

        $excelSeul = OfficeManifest::build($modele, ['gateway_url' => 'https://mia.client.fr/office'], 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff', 4, ['Workbook']);
        $this->assertSame(['Workbook'], array_values(array_unique(array_map(
            static fn(array $m): string => $m[1],
            (preg_match_all('#<Host (?:Name|xsi:type)="(\w+)"#', $excelSeul, $mm, PREG_SET_ORDER) ? $mm : [])
        ))));
        $this->assertNotFalse(simplexml_load_string($excelSeul));
        $this->assertSame($modele, OfficeManifest::keepHosts($modele, ['Workbook', 'Document', 'Presentation']), 'Les trois : rien ne bouge.');

        $this->expectException(\RuntimeException::class);
        OfficeManifest::keepHosts($modele, ['Mailbox']);
    }

    public function testLesEcransMontrentLesApplicationsEtLeursIcones(): void
    {
        $admin = self::lire('templates/admin/connectors-office.php');
        $this->assertStringContainsString('name="apps[]"', $admin);
        $this->assertStringContainsString('OfficeAddin::appIcon($app', $admin);
        $this->assertStringContainsString('OfficeAddin::appIcons(', self::lire('templates/admin/connectors.php'));
        $this->assertStringContainsString('OfficeAddin::appIcons(', self::lire('templates/settings/partials/office-addin-card.php'));
        $this->assertStringContainsString('SETTING_APPS', self::lire('src/Controllers/OfficeAddinController.php'));
        $this->assertStringContainsString('OfficeAddin::hostsFor($apps)', self::lire('src/Office/OfficeManifest.php'));
        $this->assertStringContainsString("'_apps'", self::lire('src/Office/OfficeManifest.php'), 'Changer d’applications change la version du manifeste.');
        $this->assertStringContainsString('.office-app-tile', self::lire('public/assets/css/main.css'));
    }

    public function testLeJetonPersonnelALaFormeAttendue(): void
    {
        $this->assertTrue(OfficeAddinToken::looksValid(OfficeAddinToken::PREFIX . str_repeat('a', 43)));
        $this->assertFalse(OfficeAddinToken::looksValid(OfficeAddinToken::PREFIX . str_repeat('a', 42)));
        $this->assertFalse(OfficeAddinToken::looksValid('sk-ant-' . str_repeat('a', 43)));
        $this->assertSame(64, strlen(OfficeAddinToken::hash('x')));
        $this->assertSame('…abcdef', OfficeAddinToken::hintOf('mia_office_zzzabcdef'));
    }

    public function testLeManifesteEstCeluiDAnthropicAvecLAdresseDeMia(): void
    {
        $modele = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OfficeApp xmlns="http://schemas.microsoft.com/office/appforoffice/1.1" xmlns:bt="http://schemas.microsoft.com/office/officeappbasictypes/1.0">
  <Id>4e2fd29d-dad0-40b3-af3c-a16e347a5ddc</Id>
  <Version>1.0.0.12</Version>
  <ProviderName>Anthropic</ProviderName>
  <DefaultSettings>
    <SourceLocation DefaultValue="https://pivot.claude.ai/?m=unified-1.0.0.12" />
  </DefaultSettings>
  <Resources>
    <bt:Urls>
      <bt:Url id="Taskpane.Url" DefaultValue="https://pivot.claude.ai/?m=unified-1.0.0.12"/>
    </bt:Urls>
  </Resources>
</OfficeApp>
XML;
        $params = OfficeAddin::manifestParams('https://mia.client.fr/office', true, self::CLIENT, true);
        $xml = OfficeManifest::build($modele, $params, 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff', 3);

        $this->assertStringContainsString('<Id>bbbbbbbb-cccc-4ddd-8eee-ffffffffffff</Id>', $xml, 'Identifiant propre à l’instance.');
        $this->assertStringNotContainsString('4e2fd29d', $xml);
        $this->assertStringContainsString('<Version>1.0.0.3</Version>', $xml);
        $attendu = 'https://pivot.claude.ai/?m=unified-1.0.0.12&amp;gateway_url=https%3A%2F%2Fmia.client.fr%2Foffice&amp;gateway_api_format=anthropic';
        $this->assertSame(2, substr_count($xml, $attendu), 'Les deux emplacements lus par Office portent la même adresse.');
        $this->assertStringContainsString('gateway_auth_source=entra', $xml);
        $this->assertStringNotContainsString('#', $xml, 'Aucun secret, donc rien après #.');
        $this->assertNotFalse(simplexml_load_string($xml));

        $this->assertSame('1.0.0.12', OfficeManifest::templateVersion($modele));
        $this->expectException(\RuntimeException::class);
        OfficeManifest::build('<OfficeApp><Id>x</Id></OfficeApp>', $params, 'id', 1);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLaPasserelleEstServieAvantLaSessionEtLeCsrf(): void
    {
        $index = self::lire('public/index.php');
        $route = strpos($index, 'OfficeGatewayController::handle(');
        $session = strpos($index, "\nSession::start();");
        $this->assertNotFalse($route);
        $this->assertNotFalse($session);
        $this->assertLessThan($session, $route, 'L’add-in n’a ni cookie ni jeton CSRF de MIA.');
        $this->assertStringContainsString("str_starts_with(\$requestPath, OfficeAddin::PATH_PREFIX . '/')", $index);

        foreach (["'/admin/connectors/office'", "'/admin/connectors/office/settings'", "'/admin/connectors/office/manifest'",
                  "'/admin/connectors/office/test'", "'/settings/office/token'", "'/settings/office/token/delete'"] as $r) {
            $this->assertStringContainsString($r, $index);
        }
        $this->assertStringContainsString('E=HTTP_AUTHORIZATION:%{HTTP:Authorization}', self::lire('public/.htaccess'),
            'L’en-tête Authorization doit arriver à PHP.');
    }

    public function testLesClassesSontChargeesPartout(): void
    {
        $classes = self::lire('config/classes.php');
        $boot = self::lire('tests/bootstrap.php');
        foreach (['src/Auth/EntraJwt.php', 'src/Office/OfficeAddin.php', 'src/Office/OfficeAddinToken.php', 'src/Office/OfficeGateway.php', 'src/Office/OfficeManifest.php'] as $f) {
            $this->assertStringContainsString("/$f'", $classes, $f);
            $this->assertStringContainsString("'/$f'", $boot, $f);
        }
        foreach (['OfficeGatewayController', 'OfficeAddinController'] as $c) {
            $this->assertStringContainsString("/src/Controllers/$c.php'", $classes);
        }
    }

    public function testDroitSourceSauvegardeMigration(): void
    {
        $this->assertTrue(Permissions::isKnown('office_addin.use'));
        $this->assertContains('office_addin.use', Permissions::defaultsFor('user'));
        $this->assertContains('office_addin.use', Permissions::defaultsFor('creator'));

        $this->assertTrue(UsageSource::isKnown(UsageSource::OFFICE_ADDIN));
        $this->assertSame(UsageSource::CAT_OFFICE, UsageSource::category(UsageSource::OFFICE_ADDIN));
        $this->assertSame(UsageSource::CAT_OFFICE, UsageSource::category(UsageSource::OFFICE_SEARCH));
        $this->assertSame(UsageSource::UNIT_SEARCHES, UsageSource::unit(UsageSource::OFFICE_SEARCH));
        $this->assertFalse(UsageSource::isQuotaExempt(UsageSource::OFFICE_SEARCH));
        $this->assertSame(UsageSource::OFFICE_SEARCH, UsageSource::searchSourceFor(UsageSource::OFFICE_ADDIN));
        $this->assertSame(UsageSource::WEB_SEARCH, UsageSource::searchSourceFor(UsageSource::CHAT));
        $this->assertSame(UsageSource::FAM_CONVERSATION, UsageSource::categoryFamily(UsageSource::CAT_OFFICE));
        $this->assertFalse(UsageSource::isQuotaExempt(UsageSource::OFFICE_ADDIN), 'Compté dans le quota, comme une conversation.');

        $this->assertContains('office_addin_tokens', BackupService::EXCLUDED_TABLES);
        $sql = self::lire('database/migrations/103_office_addin.sql');
        $this->assertStringContainsString('CREATE TABLE office_addin_tokens', $sql);
        $this->assertStringContainsString('token_hash   CHAR(64)', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testLaPasserelleNeGardeAucunContenuEtComptePasUsageMeter(): void
    {
        $ctrl = self::lire('src/Controllers/OfficeGatewayController.php');
        $this->assertStringContainsString('UsageMeter::llm(', $ctrl);
        $this->assertStringContainsString('UsageSource::OFFICE_ADDIN', $ctrl);
        $this->assertStringContainsString('CreditQuota::isExceeded(', $ctrl);
        $this->assertStringContainsString("Role::userCan(\$user, 'office_addin.use')", $ctrl);
        $this->assertStringContainsString('ProviderIncident::note(', $ctrl);
        $this->assertStringNotContainsString('Message::create', $ctrl);
        $this->assertStringNotContainsString('Conversation::create', $ctrl);
        $this->assertStringNotContainsString('UserIdentity::link(', self::lire('src/Office/OfficeGateway.php'));
        // Aucune création de compte depuis un jeton Microsoft.
        $this->assertStringNotContainsString('User::create', $ctrl);
    }

    public function testLesDocsEtLesNouveautesEnParlent(): void
    {
        $this->assertStringContainsString('Claude dans Office', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Claude dans Office', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('Claude dans Office', self::lire('docs/GUIDE-UTILISATEUR.md'));
        $this->assertFileExists(self::racine() . '/docs/user/admin/connecteurs/claude-dans-office.md');
        $this->assertFileExists(self::racine() . '/docs/user/fonctionnalites/connecteurs/claude-dans-office.md');
    }
}
