<?php

declare(strict_types=1);

/**
 * Notifications sur les appareils, sur la vraie base : paire de clés de
 * l'instance, appareils inscrits (propriétaire unique, plafond, issues d'un
 * envoi), et le chemin complet « une exécution programmée se termine → un
 * message chiffré part vers chaque appareil de la personne ».
 *
 * Aucun réseau : le transport de WebPush est remplacé, il capture les
 * requêtes. Les messages capturés sont DÉCHIFFRÉS avec la clé privée de
 * l'appareil fictif — ce qui partirait est vérifié, pas seulement le fait que
 * quelque chose parte.
 */
final class PushNotificationsPipelineTest extends DatabaseTestCase
{
    private const NOW = '2026-10-05 06:00:00';

    /** @var list<array{url: string, headers: list<string>, body: string}> */
    private array $sent = [];
    /** Statut rendu par le faux service de notification. */
    private int $status = 201;
    /** @var array<string, array{private: OpenSSLAsymmetricKey, public: string, auth: string}> appareils fictifs par adresse */
    private array $devices = [];
    private array $reglagesAvant = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        $db->exec("DELETE FROM settings WHERE `key` IN ('push_vapid_public', 'push_vapid_private_enc')");
        Settings::refresh();
        foreach ([ScheduledTask::SETTING_ENABLED, 'default_llm', 'connector_web_enabled'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(ScheduledTask::SETTING_ENABLED, '1');
        Settings::set('connector_web_enabled', '1');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'), 'display_name' => 'Défaut', 'is_active' => 1, 'sort_order' => 0]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));

        // Comme en vrai : la page crée la paire de clés AVANT toute
        // inscription (le navigateur s'inscrit avec la clé publique).
        WebPush::keys();

        $this->sent = [];
        $this->status = 201;
        WebPush::overrideTransport(function (array $requests): array {
            foreach ($requests as $r) {
                $this->sent[] = $r;
            }
            return array_fill(0, count($requests), $this->status);
        });
    }

    protected function tearDown(): void
    {
        WebPush::overrideTransport(null);
        if (self::$pdo !== null) {
            $db = $this->db();
            $db->exec('DELETE FROM llm_models');
            $db->exec('DELETE FROM api_keys');
            $db->exec("DELETE FROM settings WHERE `key` IN ('push_vapid_public', 'push_vapid_private_enc')");
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, $valeur);
            }
            Settings::refresh();
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    private static function now(string $decalage = ''): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        return $decalage !== '' ? $now->modify($decalage) : $now;
    }

    /** Un navigateur fictif : sa paire de clés, son secret, inscrit pour `$userId`. */
    private function appareil(int $userId, string $endpoint = ''): string
    {
        $endpoint = $endpoint !== '' ? $endpoint : 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(8));
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($key);
        $public = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);
        $this->devices[$endpoint] = ['private' => $key, 'public' => $public, 'auth' => $auth];
        PushSubscription::upsert($userId, $endpoint, WebPush::b64urlEncode($public), WebPush::b64urlEncode($auth), 'Chrome · macOS');
        return $endpoint;
    }

    /** Déchiffre un message capturé avec la clé privée de l'appareil visé (RFC 8291). */
    private function lire(array $request): array
    {
        $device = $this->devices[$request['url']];
        $body = $request['body'];
        $salt = substr($body, 0, 16);
        $idlen = ord($body[20]);
        $asPublic = substr($body, 21, $idlen);
        $cipher = substr($body, 21 + $idlen);
        $ecdh = openssl_pkey_derive(openssl_pkey_get_public(WebPush::publicKeyPem($asPublic)), $device['private']);
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $device['public'] . $asPublic, $device['auth']);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        $this->assertNotFalse($plain, 'Message indéchiffrable par l’appareil.');
        $this->assertSame("\x02", substr($plain, -1));
        return json_decode(substr($plain, 0, -1), true);
    }

    private function entete(array $request, string $nom): ?string
    {
        foreach ($request['headers'] as $h) {
            if (stripos($h, $nom . ':') === 0) {
                return trim(substr($h, strlen($nom) + 1));
            }
        }
        return null;
    }

    private function tache(int $userId, array $surcharge = []): int
    {
        $id = ScheduledTask::create($userId, $surcharge + [
            'name'        => 'Point du lundi',
            'instruction' => 'Fais le point sur mes mails.',
            'profile_id'  => null,
            'llm'         => '',
            'rule'        => ['freq' => 'weekly', 'days' => [1], 'time' => '08:00'],
            'timezone'    => 'Europe/Paris',
            'sources'     => ['web'],
            'destination' => ScheduledTask::DEST_THREAD,
        ], self::now('-7 days'));
        ScheduledTask::setNextRun($id, self::now('-1 minute'));
        return $id;
    }

    private function nombre(string $sql, array $params = []): int
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Clés de l'instance
    // ------------------------------------------------------------------

    public function testLaPaireDeClesEstCreeeUneFoisEtGardeeChiffree(): void
    {
        $this->db()->exec("DELETE FROM settings WHERE `key` IN ('push_vapid_public', 'push_vapid_private_enc')");
        Settings::refresh();
        $first = WebPush::keys();
        $this->assertSame(65, strlen(WebPush::b64urlDecode($first['public'])));
        $stored = (string)$this->db()->query("SELECT value FROM settings WHERE `key` = 'push_vapid_private_enc'")->fetchColumn();
        $this->assertStringNotContainsString('PRIVATE KEY', $stored, 'La clé privée ne dort jamais en clair.');

        Settings::refresh();
        $this->assertSame($first['public'], WebPush::keys()['public'], 'Stable d’un appel à l’autre.');
        $this->assertSame($first['public'], WebPush::publicKey());
    }

    public function testUnePaireIllisibleEstRecreeEtLesInscriptionsTombent(): void
    {
        $this->insertUser(1);
        $old = WebPush::keys()['public'];
        $this->appareil(1);
        // Clé privée illisible (clé de chiffrement changée, valeur abîmée) :
        // déchiffrée, elle ne donne plus une clé EC.
        Settings::set(WebPush::SETTING_PRIVATE, Encryption::encrypt('pas une clé'));
        Settings::refresh();

        $new = WebPush::keys()['public'];
        $this->assertNotSame($old, $new);
        // Inscrites avec l'ancienne clé publique : elles ne recevraient plus rien.
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM push_subscriptions'));
    }

    // ------------------------------------------------------------------
    // Appareils
    // ------------------------------------------------------------------

    public function testUnNavigateurNAppartientQuAUnCompte(): void
    {
        $this->insertUser(1);
        $this->insertUser(2);
        $endpoint = $this->appareil(1);
        $this->appareil(2, $endpoint); // un autre compte s'y inscrit

        $this->assertSame(0, PushSubscription::countForUser(1));
        $this->assertSame(1, PushSubscription::countForUser(2));

        // Retirer par l'adresse : quel que soit le compte (posséder l'adresse,
        // c'est être l'appareil) ; retirer par l'identifiant : le sien seulement.
        $id = PushSubscription::forUser(2)[0]['id'];
        $this->assertFalse(PushSubscription::deleteForUser($id, 1));
        $this->assertTrue(PushSubscription::deleteByEndpoint($endpoint));
        $this->assertSame(0, PushSubscription::countForUser(2));
    }

    public function testDixAppareilsAuPlusParCompte(): void
    {
        $this->insertUser(1);
        $premier = $this->appareil(1);
        for ($i = 0; $i < PushSubscription::MAX_PER_USER; $i++) {
            $this->db()->exec('UPDATE push_subscriptions SET updated_at = NOW() - INTERVAL 1 DAY');
            $this->appareil(1);
        }
        $this->assertSame(PushSubscription::MAX_PER_USER, PushSubscription::countForUser(1));
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash = ?', [PushSubscription::hash($premier)]), 'Le plus ancien laisse sa place.');
    }

    public function testLIssueDUnEnvoiSuitChaqueAppareil(): void
    {
        $this->insertUser(1);
        $this->appareil(1);
        $this->appareil(1);
        $this->appareil(1);
        [$a, $b, $c] = array_column(PushSubscription::forUser(1), 'id');

        $this->assertSame(['sent' => 1, 'removed' => 1, 'failed' => 1], PushSubscription::recordResults([$a => 201, $b => 410, $c => 503]));
        $rows = array_column(PushSubscription::forUser(1), null, 'id');
        $this->assertNotNull($rows[$a]['last_success_at']);
        $this->assertArrayNotHasKey($b, $rows, '410 : l’inscription n’existe plus.');
        $this->assertSame(1, $rows[$c]['failures']);

        // Une série d'échecs finit par retirer l'appareil.
        for ($i = 1; $i < PushSubscription::MAX_FAILURES; $i++) {
            PushSubscription::recordResults([$c => 403]);
        }
        $this->assertSame([$a], array_column(PushSubscription::forUser(1), 'id'));
    }

    // ------------------------------------------------------------------
    // Envoi
    // ------------------------------------------------------------------

    public function testCeQuiPartEstChiffrePourLAppareilEtSigne(): void
    {
        $this->insertUser(1);
        $endpoint = $this->appareil(1);
        $piege = 'http://127.0.0.1/interne';
        $this->db()->prepare('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth) VALUES (1, ?, ?, ?, ?)')
            ->execute([$piege, PushSubscription::hash($piege), WebPush::b64urlEncode("\x04" . random_bytes(64)), WebPush::b64urlEncode(random_bytes(16))]);

        $statuses = WebPush::send(PushSubscription::forUser(1), ['title' => 'Point', 'body' => 'Le résultat est arrivé.', 'url' => '/chat/3', 'tag' => 'mia-task-1'], 'mia-task-1');

        $this->assertCount(1, $this->sent, 'Une adresse hors liste blanche ne reçoit rien, même rangée en base.');
        $req = $this->sent[0];
        $this->assertSame($endpoint, $req['url']);
        $this->assertSame((string)WebPush::TTL, $this->entete($req, 'TTL'));
        $this->assertSame('aes128gcm', $this->entete($req, 'Content-Encoding'));
        $this->assertSame('mia-task-1', $this->entete($req, 'Topic'));
        $this->assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=' . preg_quote(WebPush::publicKey(), '/') . '$/', (string)$this->entete($req, 'Authorization'));
        $this->assertSame(['title' => 'Point', 'body' => 'Le résultat est arrivé.', 'url' => '/chat/3', 'tag' => 'mia-task-1'], $this->lire($req));

        $byId = array_column(PushSubscription::forUser(1), 'id', 'endpoint');
        $this->assertSame(201, $statuses[$byId[$endpoint]]);
        $this->assertSame(0, $statuses[$byId[$piege]]);
    }

    // ------------------------------------------------------------------
    // Une exécution programmée prévient
    // ------------------------------------------------------------------

    public function testUnResultatArrivePrevientLesAppareilsDeLaPersonne(): void
    {
        $this->insertUser(1);
        $this->insertUser(2);
        $this->appareil(1);
        $this->appareil(1);
        $this->appareil(2); // l'appareil d'autrui ne reçoit rien
        $task = $this->tache(1);

        $this->assertSame(1, Scheduler::tick(self::now(), false));
        $run = $this->db()->query('SELECT * FROM scheduled_task_runs WHERE task_id = ' . $task)->fetch();
        $this->assertSame([], $this->sent, 'Rien tant que le résultat n’est pas là.');

        $msg = Message::create((int)$run['conversation_id'], 'assistant', 'Fait.');
        ScheduledTaskRun::onJobFinished((int)$run['job_id'], 'done', ['msg_id' => (int)$msg['id']], null);

        $this->assertCount(2, $this->sent);
        foreach ($this->sent as $req) {
            $this->assertSame(
                ['title' => 'Point du lundi', 'body' => 'Le résultat est arrivé.', 'url' => '/chat/' . (int)$run['conversation_id'], 'tag' => 'mia-task-' . $task],
                $this->lire($req)
            );
        }
        $this->assertSame(2, $this->nombre('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = 1 AND last_success_at IS NOT NULL'));
    }

    public function testExecuterMaintenantNePrevientPas(): void
    {
        $this->insertUser(1);
        $this->appareil(1);
        $task = $this->tache(1);
        $started = Scheduler::runNow(ScheduledTask::findForUser($task, 1), self::now());
        $this->assertTrue($started['ok']);

        ScheduledTaskRun::onJobFinished($started['job_id'], 'done', [], null);
        $this->assertSame([], $this->sent, 'La personne regarde l’écran.');
    }

    public function testUneTacheQuiNeLeDemandePasNePrevientPas(): void
    {
        $this->insertUser(1);
        $this->appareil(1);
        $task = $this->tache(1, ['notify' => false]);
        Scheduler::tick(self::now(), false);
        $jobId = (int)$this->db()->query('SELECT job_id FROM scheduled_task_runs WHERE task_id = ' . $task)->fetchColumn();

        ScheduledTaskRun::onJobFinished($jobId, 'done', [], null);
        $this->assertSame([], $this->sent);
    }

    public function testUneExecutionSauteePourLeQuotaEstDite(): void
    {
        $this->insertUser(1);
        $this->appareil(1);
        $task = $this->tache(1);
        $this->db()->exec('UPDATE users SET credit_quota_k = 0 WHERE id = 1');

        Scheduler::tick(self::now(), false);

        $this->assertCount(1, $this->sent);
        $message = $this->lire($this->sent[0]);
        $this->assertSame('Exécution sautée : quota de crédits atteint.', $message['body']);
        $this->assertSame('/settings/schedules/' . $task . '/edit', $message['url']);
    }

    public function testUnAppareilQuiNExistePlusEstOublie(): void
    {
        $this->insertUser(1);
        $this->appareil(1);
        $task = $this->tache(1);
        Scheduler::tick(self::now(), false);
        $jobId = (int)$this->db()->query('SELECT job_id FROM scheduled_task_runs WHERE task_id = ' . $task)->fetchColumn();

        $this->status = 410;
        ScheduledTaskRun::onJobFinished($jobId, 'failed', null, ['kind' => 'server']);
        $this->assertCount(1, $this->sent);
        $this->assertSame('L’exécution n’a pas abouti.', $this->lire($this->sent[0])['body']);
        $this->assertSame(0, PushSubscription::countForUser(1));
    }

    public function testUnEnvoiImpossibleNEmpecheRien(): void
    {
        $this->insertUser(1);
        $this->appareil(1);
        $task = $this->tache(1);
        Scheduler::tick(self::now(), false);
        $run = $this->db()->query('SELECT * FROM scheduled_task_runs WHERE task_id = ' . $task)->fetch();
        WebPush::overrideTransport(static function (): array {
            throw new \RuntimeException('réseau coupé');
        });

        ScheduledTaskRun::onJobFinished((int)$run['job_id'], 'done', [], null);
        // L'issue est enregistrée, la pastille posée : la notification n'était qu'un plus.
        $this->assertSame('done', $this->db()->query('SELECT status FROM scheduled_task_runs WHERE id = ' . (int)$run['id'])->fetchColumn());
        $this->assertNotNull($this->db()->query('SELECT unread_at FROM conversations WHERE id = ' . (int)$run['conversation_id'])->fetchColumn());
    }

    public function testLaNotificationEstDansLaLangueDeLaPersonne(): void
    {
        $this->insertUser(1);
        $this->db()->exec("UPDATE users SET lang = 'en' WHERE id = 1");
        $this->appareil(1);
        $task = $this->tache(1);
        Scheduler::tick(self::now(), false);
        $jobId = (int)$this->db()->query('SELECT job_id FROM scheduled_task_runs WHERE task_id = ' . $task)->fetchColumn();
        I18n::use('fr');

        ScheduledTaskRun::onJobFinished($jobId, 'done', [], null);
        $this->assertSame('The result is in.', $this->lire($this->sent[0])['body']);
        $this->assertSame('fr', I18n::locale(), 'La locale du processus est rendue.');
    }
}
