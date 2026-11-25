<?php

declare(strict_types=1);

/**
 * Passerelle de « Claude dans Office », de bout en bout sur MySQL réel, face à
 * un faux serveur Anthropic (`php -S`, tests/Support/fake_anthropic.php) :
 * jeton personnel et jeton Microsoft signé, flux relayé à l'octet près, usage
 * compté au grand livre au compte de la personne, refus (passerelle coupée,
 * jeton inconnu, modèle non permis, quota, crédit épuisé chez Anthropic).
 *
 * Auto-skippé sans base ou sans `proc_open`.
 */
final class OfficeGatewayPipelineTest extends DatabaseTestCase
{
    private const TENANT = '11111111-2222-4333-8444-555555555555';
    private const CLIENT = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    private const MODEL  = 'claude-opus-5';

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $log = '';
    private static string $jwksDir = '';
    private static ?\OpenSSLAsymmetricKey $key = null;

    private const SETTINGS = [
        OfficeAddin::SETTING_ENABLED, OfficeAddin::SETTING_AUTH, OfficeAddin::SETTING_CLIENT_ID,
        OfficeAddin::SETTING_TENANT, OfficeAddin::SETTING_WEB_SEARCH, 'default_llm',
    ];
    private array $before = [];

    public static function setUpBeforeClass(): void
    {
        require_once ROOT . '/src/Controllers/OfficeGatewayController.php';
        require_once ROOT . '/src/Controllers/OfficeAddinController.php';
        // La carte de la personne propose l'assistant d'aide (« Me guider pas à pas »).
        require_once ROOT . '/src/Models/DefaultAssistant.php';
        if (!function_exists('proc_open')) {
            return;
        }
        self::$log = sys_get_temp_dir() . '/mia-fake-anthropic-' . bin2hex(random_bytes(4)) . '.log';
        $sock = @stream_socket_server('tcp://127.0.0.1:0');
        if ($sock === false) {
            return;
        }
        self::$port = (int)explode(':', (string)stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, ROOT . '/tests/Support/fake_anthropic.php'];
        self::$server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, ROOT, ['FAKE_ANTHROPIC_LOG' => self::$log] + getenv());
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($c !== false) {
                fclose($c);
                break;
            }
            usleep(100000);
        }
        self::$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]) ?: null;
        self::$jwksDir = sys_get_temp_dir() . '/mia-jwks-' . bin2hex(random_bytes(4));
        @mkdir(self::$jwksDir);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$log);
        array_map('unlink', glob(self::$jwksDir . '/*') ?: []);
        @rmdir(self::$jwksDir);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_resource(self::$server) || self::$key === null) {
            $this->markTestSkipped('Faux serveur Anthropic indisponible.');
        }
        foreach (self::SETTINGS as $k) {
            $this->before[$k] = Settings::get($k);
        }
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        $this->db()->exec('DELETE FROM office_addin_tokens');
        ApiKey::save('anthropic', 'sk-ant-instance-secrete');
        LlmModel::save(['provider' => 'anthropic', 'model' => self::MODEL, 'display_name' => 'Claude Opus 5', 'is_active' => 1, 'sort_order' => 1]);
        LlmModel::save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5', 'is_active' => 1, 'sort_order' => 2]);
        Settings::set('default_llm', 'anthropic::' . self::MODEL);
        Settings::set(OfficeAddin::SETTING_ENABLED, '1');
        Settings::set(OfficeAddin::SETTING_AUTH, OfficeAddin::AUTH_BOTH);
        Settings::set(OfficeAddin::SETTING_CLIENT_ID, self::CLIENT);
        Settings::set(OfficeAddin::SETTING_TENANT, self::TENANT);
        OfficeGatewayController::overrideUpstream('http://127.0.0.1:' . self::$port);
        @file_put_contents(self::$log, '');

        $d = openssl_pkey_get_details(self::$key)['rsa'];
        $jwks = (string)json_encode(['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k1',
            'n' => EntraJwt::base64UrlEncode($d['n']), 'e' => EntraJwt::base64UrlEncode($d['e'])]]]);
        EntraJwt::overrideCacheDir(self::$jwksDir);
        EntraJwt::overrideFetcher(static fn(string $url): string => $jwks);
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            foreach ($this->before as $k => $v) {
                Settings::set($k, $v ?? '');
            }
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            $this->db()->exec('DELETE FROM office_addin_tokens');
        }
        OfficeGatewayController::overrideUpstream(null);
        EntraJwt::overrideFetcher(null);
        EntraJwt::overrideCacheDir(null);
        parent::tearDown();
    }

    private function call(string $method, string $path, array $headers, ?string $body = null): string
    {
        ob_start();
        OfficeGatewayController::handle($method, $path, $body, array_change_key_case($headers));
        return (string)ob_get_clean();
    }

    private function messages(string $credential, array $body, string $header = 'authorization'): string
    {
        $h = $header === 'authorization' ? ['authorization' => 'Bearer ' . $credential] : ['x-api-key' => $credential];
        return $this->call('POST', '/office/v1/messages', $h + ['anthropic-version' => '2023-06-01', 'accept' => 'text/event-stream'],
            (string)json_encode($body));
    }

    private static function erreur(string $out): array
    {
        $j = json_decode($out, true);
        return is_array($j) && ($j['type'] ?? '') === 'error' ? $j['error'] : [];
    }

    private function usageRows(): array
    {
        return $this->db()->query("SELECT * FROM usage_logs WHERE source IN ('office_addin', 'office_search', 'web_search') ORDER BY id")->fetchAll();
    }

    private function upstreamCalls(): array
    {
        $lines = array_filter(explode("\n", (string)@file_get_contents(self::$log)));
        return array_map(static fn(string $l): array => json_decode($l, true), array_values($lines));
    }

    private function jwt(array $claims): string
    {
        $now = time();
        $claims += [
            'iss' => 'https://login.microsoftonline.com/' . self::TENANT . '/v2.0', 'tid' => self::TENANT,
            'aud' => self::CLIENT, 'scp' => 'access_as_user', 'oid' => '99999999-8888-4777-8666-555555555555',
            'nbf' => $now - 60, 'exp' => $now + 3600,
        ];
        $h = EntraJwt::base64UrlEncode('{"alg":"RS256","typ":"JWT","kid":"k1"}');
        $p = EntraJwt::base64UrlEncode((string)json_encode($claims));
        openssl_sign($h . '.' . $p, $sig, self::$key, OPENSSL_ALGO_SHA256);
        return $h . '.' . $p . '.' . EntraJwt::base64UrlEncode($sig);
    }

    // ------------------------------------------------------------------

    public function testLeJetonPersonnelOuvreLaPasserelleEtLaReponseEstComptee(): void
    {
        $uid = $this->insertUser(7);
        $token = OfficeAddinToken::issue($uid);
        $body = ['model' => self::MODEL, 'max_tokens' => 1024, 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'Somme de la colonne B ?']]];

        $out = $this->messages($token, $body);

        // Relayé à l'octet près : même flux que le faux serveur rend en direct.
        $direct = (string)file_get_contents('http://127.0.0.1:' . self::$port . '/v1/messages', false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($body),
        ]]));
        $this->assertSame($direct, $out);
        $this->assertStringContainsString('"id":"msg_fake_01"', $out, 'L’identifiant de réponse passe inchangé.');

        // Anthropic n'a vu que la clé de l'instance — ni le jeton MIA, ni l'en-tête Authorization.
        $appels = $this->upstreamCalls();
        $avecPasserelle = $appels[0];
        $this->assertSame('sk-ant-instance-secrete', $avecPasserelle['headers']['x-api-key'] ?? null);
        $this->assertArrayNotHasKey('authorization', $avecPasserelle['headers']);
        $this->assertStringNotContainsString($token, json_encode($avecPasserelle));
        $this->assertSame(json_encode($body), $avecPasserelle['body'], 'Le corps part tel quel.');

        // Compté au grand livre, au compte de la personne, sans conversation.
        $rows = $this->usageRows();
        $this->assertCount(2, $rows, 'Une ligne pour les jetons, une pour la recherche Internet.');
        $this->assertSame('office_addin', $rows[0]['source']);
        $this->assertSame($uid, (int)$rows[0]['user_id']);
        $this->assertNull($rows[0]['conversation_id']);
        $this->assertSame(3100, (int)$rows[0]['tokens_input']);
        $this->assertSame(500, (int)$rows[0]['tokens_input_cached']);
        $this->assertSame(320, (int)$rows[0]['tokens_output']);
        $this->assertSame('anthropic::' . self::MODEL, $rows[0]['llm_provider']);
        $this->assertGreaterThan(0, (int)$rows[0]['credits_used']);
        // La recherche reste dans la catégorie de Claude dans Office : tout ce
        // que l'add-in a coûté se lit au même endroit.
        $this->assertSame('office_search', $rows[1]['source']);
        $this->assertSame(UsageSource::CAT_OFFICE, UsageSource::category($rows[1]['source']));
        $this->assertSame(1.0, (float)$rows[1]['quantity']);
        $this->assertNull($rows[1]['conversation_id']);
        $this->assertSame($uid, (int)$rows[1]['user_id']);

        // Même jeton dans x-api-key (défaut de l'add-in sans manifeste) : accepté.
        $out2 = $this->messages($token, ['model' => self::MODEL, 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'x']]], 'x-api-key');
        $this->assertSame('msg_fake_02', json_decode($out2, true)['id'] ?? null);
        $rows = $this->usageRows();
        $this->assertSame(40, (int)$rows[2]['tokens_input']);
        $this->assertSame(3, (int)$rows[2]['tokens_output']);
    }

    /**
     * Une requête qu'Anthropic facture plus cher (cache écrit pour une heure,
     * mode rapide, inférence aux États-Unis) l'est aussi au grand livre : au
     * tarif réel, jamais au tarif normal.
     */
    public function testUneRequeteAuTarifMajoreEstCompteeAuTarifMajore(): void
    {
        $uid = $this->insertUser(9);
        $token = OfficeAddinToken::issue($uid);
        $body = ['model' => self::MODEL, 'max_tokens' => 10,
            'system' => [['type' => 'text', 'text' => str_repeat('Consignes. ', 50), 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]],
            'speed' => 'fast', 'inference_geo' => 'us',
            'messages' => [['role' => 'user', 'content' => 'x']]];
        $this->messages($token, $body);

        $row = $this->usageRows()[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(1040, (int)$row['tokens_input'], 'Entrée totale : hors cache + écrite.');
        $this->assertSame(1000, (int)$row['tokens_input_cache_write']);

        $key = 'anthropic::' . self::MODEL;
        $attendu = CreditService::computeLlmCredits($key, 1040, 3, 0, 1000, 0, [], 0, 1000, 2.2);
        $this->assertEqualsWithDelta($attendu['cost_eur'], (float)$row['cost_eur'], 0.000002);
        $this->assertSame((int)$attendu['total_credits'], (int)$row['credits_used']);

        // Le même appel au tarif normal coûte 2,2 fois moins, et l'heure de
        // cache vaut 2× l'entrée au lieu de 1,25×.
        $normal = CreditService::computeLlmCredits($key, 1040, 3, 0, 1000, 0, [], 0, 1000, 1.0);
        $this->assertEqualsWithDelta($normal['cost_eur'] * 2.2, (float)$row['cost_eur'], 0.000002);
        $cinqMinutes = CreditService::computeLlmCredits($key, 1040, 3, 0, 1000);
        $entree = CreditService::computeLlmCredits($key, 1000, 0)['cost_eur'];
        $this->assertGreaterThan(0.0, $entree);
        $this->assertEqualsWithDelta(($normal['cost_eur'] - $cinqMinutes['cost_eur']), $entree * (2.0 - 1.25), 0.000002);
    }

    /**
     * Les écrans lisent Claude dans Office à part : sa tranche dans « par
     * assistant » (pas « Sans assistant »), son résumé du mois (carte de la
     * personne, page d'administration), sa catégorie.
     */
    public function testLesEcransLisentClaudeDansOfficeAPart(): void
    {
        $uid = $this->insertUser(11);
        $autre = $this->insertUser(12);
        UsageMeter::llm('anthropic::' . self::MODEL, ['input' => 2000, 'output' => 100, 'web_searches' => 2], UsageSource::OFFICE_ADDIN, ['user_id' => $uid]);
        UsageMeter::llm('anthropic::' . self::MODEL, ['input' => 1000, 'output' => 50], UsageSource::OFFICE_ADDIN, ['user_id' => $uid]);
        UsageMeter::llm('anthropic::' . self::MODEL, ['input' => 500, 'output' => 20], UsageSource::OFFICE_ADDIN, ['user_id' => $autre]);
        UsageMeter::llm('anthropic::' . self::MODEL, ['input' => 800, 'output' => 40], UsageSource::CHAT, ['user_id' => $uid]);

        $mois = [date('Y-m-01'), date('Y-m-t')];
        $perso = UsageLog::officeSummary($mois[0], $mois[1], $uid);
        $this->assertSame(2, $perso['requests']);
        $this->assertSame(2, $perso['searches']);
        $this->assertSame(1, $perso['people']);
        $officeCredits = (int)$this->db()->query("SELECT SUM(credits_used) FROM usage_logs WHERE user_id = $uid AND source IN ('office_addin', 'office_search')")->fetchColumn();
        $this->assertSame($officeCredits, $perso['credits'], 'Recherches comprises.');
        $tous = UsageLog::officeSummary($mois[0], $mois[1]);
        $this->assertSame(3, $tous['requests']);
        $this->assertSame(2, $tous['people']);

        $split = UsageLog::quotaCreditsSplit($mois[0], $mois[1], $uid);
        $office = array_values(array_filter($split['profiles'], static fn(array $p): bool => $p['office']));
        $sans   = array_values(array_filter($split['profiles'], static fn(array $p): bool => !$p['office'] && $p['profile_id'] === null));
        $this->assertCount(1, $office);
        $this->assertSame($officeCredits, $office[0]['credits']);
        $this->assertCount(1, $sans, 'La conversation sans assistant reste à part.');

        $cats = array_column(UsageLog::byCategory($mois[0], $mois[1], $uid), null, 'category');
        $this->assertSame($officeCredits, (int)$cats[UsageSource::CAT_OFFICE]['credits']);
        $this->assertSame(1, (int)$cats[UsageSource::CAT_CHAT]['requests'], 'Les demandes Office ne gonflent plus « Conversations ».');

        $card = OfficeAddinController::cardFor(User::findById($uid));
        $this->assertSame(2, $card['usage']['requests'] ?? null);
    }

    public function testLeJetonMicrosoftSeRattacheAuCompteParSonAdresse(): void
    {
        $uid = $this->insertUser(8);
        $this->db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['marie.dupont@client.fr', $uid]);
        $jwt = $this->jwt(['preferred_username' => 'Marie.Dupont@client.fr']);

        $out = $this->call('GET', '/office/v1/models', ['authorization' => 'Bearer ' . $jwt]);
        $models = json_decode($out, true);
        $this->assertSame([self::MODEL], array_column($models['data'] ?? [], 'id'), 'Sans groupe : le modèle par défaut seulement, comme le chat.');

        $this->assertNotNull(UserIdentity::findUser(UserIdentity::MICROSOFT, self::TENANT . '.99999999-8888-4777-8666-555555555555'),
            'L’identité est rattachée : le prochain jeton se reconnaît par son oid.');

        // Adresse changée côté Microsoft : l'oid suffit désormais.
        $out2 = $this->call('GET', '/office/v1/models', ['authorization' => 'Bearer ' . $this->jwt(['preferred_username' => 'm.dupont@client.fr'])]);
        $this->assertNotEmpty(json_decode($out2, true)['data'] ?? []);

        // Personne inconnue : refus clair, aucun compte créé.
        $inconnu = $this->jwt(['oid' => '12121212-1212-4121-8121-121212121212', 'preferred_username' => 'inconnu@client.fr']);
        $err = self::erreur($this->call('GET', '/office/v1/models', ['authorization' => 'Bearer ' . $inconnu]));
        $this->assertSame('permission_error', $err['type'] ?? null);
        $this->assertStringContainsString('inconnu@client.fr', $err['message']);
        $this->assertSame(1, (int)$this->db()->query('SELECT COUNT(*) FROM users')->fetchColumn());

        // Jeton d'un autre annuaire, ou mode « jetons seulement » : refusé.
        $autre = $this->jwt(['tid' => '22222222-2222-4333-8444-555555555555', 'iss' => 'https://login.microsoftonline.com/22222222-2222-4333-8444-555555555555/v2.0']);
        $this->assertSame('authentication_error', self::erreur($this->call('GET', '/office/v1/models', ['authorization' => 'Bearer ' . $autre]))['type'] ?? null);
        Settings::set(OfficeAddin::SETTING_AUTH, OfficeAddin::AUTH_TOKEN);
        $this->assertSame('authentication_error', self::erreur($this->call('GET', '/office/v1/models', ['authorization' => 'Bearer ' . $jwt]))['type'] ?? null);
    }

    public function testLesGroupesDecidentDesModeles(): void
    {
        $uid = $this->insertUser(9);
        $g = $this->insertGroup('Finance');
        $this->addMember($uid, $g);
        $this->addGroupLlm($g, 'anthropic::claude-sonnet-5');
        $token = OfficeAddinToken::issue($uid);

        $models = json_decode($this->call('GET', '/office/v1/models', ['x-api-key' => $token]), true);
        $this->assertSame(['claude-sonnet-5'], array_column($models['data'], 'id'));
        $one = json_decode($this->call('GET', '/office/v1/models/claude-sonnet-5', ['x-api-key' => $token]), true);
        $this->assertSame('claude-sonnet-5', $one['id'] ?? null);

        $err = self::erreur($this->messages($token, ['model' => self::MODEL, 'max_tokens' => 10, 'messages' => []]));
        $this->assertSame('permission_error', $err['type'] ?? null);
        $this->assertStringContainsString('claude-sonnet-5', $err['message'], 'Le refus nomme les modèles disponibles.');
        $this->assertSame([], $this->upstreamCalls(), 'Rien n’est parti chez Anthropic.');
    }

    public function testLesRefusAvantToutAppelPayant(): void
    {
        $uid = $this->insertUser(10);
        $token = OfficeAddinToken::issue($uid);
        $body = ['model' => self::MODEL, 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'x']]];

        // Quota personnel atteint : refusé, rien ne part.
        $this->db()->prepare('UPDATE users SET credit_quota_k = 1 WHERE id = ?')->execute([$uid]);
        $this->db()->prepare("INSERT INTO usage_logs (user_id, llm_provider, model, tokens_input, tokens_output, credits_used, cost_eur, source, created_at) VALUES (?, 'anthropic::claude-opus-5', 'claude-opus-5', 1, 1, 5000, 0, 'chat', NOW())")->execute([$uid]);
        $err = self::erreur($this->messages($token, $body));
        $this->assertSame('permission_error', $err['type'] ?? null);
        $this->assertSame([], $this->upstreamCalls());
        $this->db()->prepare('UPDATE users SET credit_quota_k = NULL WHERE id = ?')->execute([$uid]);

        // Jeton révoqué, jeton inventé, rien du tout.
        OfficeAddinToken::revoke($uid);
        $this->assertSame('authentication_error', self::erreur($this->messages($token, $body))['type'] ?? null);
        $this->assertSame('authentication_error', self::erreur($this->messages('mia_office_' . str_repeat('z', 43), $body))['type'] ?? null);
        $this->assertSame('authentication_error', self::erreur($this->call('POST', '/office/v1/messages', [], '{}'))['type'] ?? null);

        // Compte désactivé.
        $token = OfficeAddinToken::issue($uid);
        $this->db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$uid]);
        $this->assertSame('permission_error', self::erreur($this->messages($token, $body))['type'] ?? null);
        $this->db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$uid]);

        // Passerelle coupée : tout est refusé, préflight compris pour le reste.
        Settings::set(OfficeAddin::SETTING_ENABLED, '0');
        $this->assertStringContainsString('pas activé', self::erreur($this->messages($token, $body))['message'] ?? '');
        $this->assertSame('', $this->call('OPTIONS', '/office/v1/messages', ['access-control-request-headers' => 'authorization']));
        Settings::set(OfficeAddin::SETTING_ENABLED, '1');

        // Corps illisible, adresse inconnue.
        $this->assertSame('invalid_request_error', self::erreur($this->call('POST', '/office/v1/messages', ['x-api-key' => $token], 'pas du json'))['type'] ?? null);
        $this->assertSame('not_found_error', self::erreur($this->call('GET', '/office/v1/files', ['x-api-key' => $token]))['type'] ?? null);
        $this->assertSame([], $this->upstreamCalls());
        $this->assertSame([], $this->usageRows());
    }

    public function testUnCreditEpuiseChezAnthropicSeDitAvecLesMotsDeMia(): void
    {
        $uid = $this->insertUser(11);
        LlmModel::save(['provider' => 'anthropic', 'model' => 'claude-credit-test', 'display_name' => 'Claude crédit', 'is_active' => 1, 'sort_order' => 3]);
        $g = $this->insertGroup('Tous');
        $this->addMember($uid, $g);
        $this->addGroupLlm($g, 'anthropic::claude-credit-test');
        $token = OfficeAddinToken::issue($uid);

        $err = self::erreur($this->messages($token, ['model' => 'claude-credit-test', 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'x']]]));
        $this->assertNotSame([], $err);
        $this->assertStringNotContainsString('Plans & Billing', $err['message'], 'Le message brut d’Anthropic ne part pas.');
        $this->assertStringContainsString('Anthropic', $err['message']);
        $this->assertSame('quota', ProviderIncident::active()['anthropic']['kind'] ?? null,
            'Consigné pour l’administration (point à régler).');
        $this->assertSame([], $this->usageRows(), 'Rien de facturé.');
    }

    public function testLeCompteDesJetonsEstRelayeSansFacturer(): void
    {
        $uid = $this->insertUser(12);
        $token = OfficeAddinToken::issue($uid);
        $out = $this->call('POST', '/office/v1/messages/count_tokens', ['x-api-key' => $token], (string)json_encode(['model' => self::MODEL, 'messages' => []]));
        $this->assertSame('{"input_tokens":42}', $out);
        $this->assertSame('/v1/messages/count_tokens', $this->upstreamCalls()[0]['path']);
        $this->assertSame([], $this->usageRows());
    }
}
