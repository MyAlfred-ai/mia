<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Notifications sur les appareils (Web Push) : le protocole, sans réseau.
 *
 *  - Chiffrement RFC 8291 : le VECTEUR de l'annexe A, reproduit à l'octet ;
 *    puis un message chiffré ici et déchiffré par une implémentation
 *    INDÉPENDANTE (Node, module crypto) — une erreur symétrique (même faute
 *    au chiffrement et au déchiffrement) ne passerait pas.
 *  - Jeton VAPID (RFC 8292) : vérifié par openssl ET par Node.
 *  - Liste blanche des services de notification : le serveur n'écrit jamais
 *    ailleurs, l'adresse venant du navigateur.
 *  - Ce que dit une notification (TaskNotifier::message) et le câblage.
 */
final class WebPushTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
        require_once ROOT . '/src/Controllers/PushController.php';
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function src(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    private static function b(string $b64url): string
    {
        return WebPush::b64urlDecode(str_replace(' ', '', $b64url));
    }

    /** Lance un script Node (JSON sur l'entrée standard), rend sa sortie JSON décodée. */
    private function node(string $script, array $input): mixed
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $fichier = tempnam(sys_get_temp_dir(), 'webpush') . '.js';
        file_put_contents($fichier, $script);
        try {
            $proc = proc_open([$node, $fichier], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], (string)json_encode($input));
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
        } finally {
            @unlink($fichier);
        }
        $this->assertSame(0, $code, 'Node : ' . $err);
        return json_decode((string)$out, true);
    }

    // ------------------------------------------------------------------
    // Chiffrement (RFC 8291)
    // ------------------------------------------------------------------

    public function testLeVecteurDeLAnnexeAEstReproduitALOctet(): void
    {
        $asPublic  = self::b('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
        $asPrivate = self::b('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
        $uaPublic  = self::b('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4');
        $auth      = self::b('BTBZMqHH6r4Tts7J_aSIgg');
        $salt      = self::b('DGv6ra1nlYgDCS1FRnbzlw');

        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            $uaPublic,
            $auth,
            ['private_pem' => WebPush::privateKeyPem($asPrivate, $asPublic), 'public' => $asPublic],
            $salt
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::b64urlEncode($body)
        );
        // En-tête de 86 octets : sel, taille de bloc 4096, clé de 65 octets.
        $this->assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        $this->assertSame(65, ord($body[20]));
    }

    public function testUnMessageChiffreIciSeDechiffreAilleurs(): void
    {
        // Un appareil fictif : sa paire de clés et son secret.
        $device = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($device);
        $uaPrivate = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);
        $uaPublic = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);
        $message = '{"title":"Point du lundi","body":"Le résultat est arrivé.","url":"/chat/12"}';

        $body = WebPush::encrypt($message, $uaPublic, $auth);
        $other = WebPush::encrypt($message, $uaPublic, $auth);
        $this->assertNotSame($body, $other, 'Clé éphémère et sel neufs à chaque message.');

        $out = $this->node(<<<'JS'
            const crypto = require('crypto');
            let raw = '';
            process.stdin.on('data', (c) => { raw += c; });
            process.stdin.on('end', () => {
                const i = JSON.parse(raw);
                const b = (s) => Buffer.from(s, 'base64url');
                const body = b(i.body);
                const salt = body.subarray(0, 16);
                const rs = body.readUInt32BE(16);
                const idlen = body[20];
                const asPublic = body.subarray(21, 21 + idlen);
                const ct = body.subarray(21 + idlen);
                const ecdh = crypto.createECDH('prime256v1');
                ecdh.setPrivateKey(b(i.uaPrivate));
                const shared = ecdh.computeSecret(asPublic);
                const keyInfo = Buffer.concat([Buffer.from('WebPush: info\0'), b(i.uaPublic), asPublic]);
                const ikm = Buffer.from(crypto.hkdfSync('sha256', shared, b(i.auth), keyInfo, 32));
                const cek = Buffer.from(crypto.hkdfSync('sha256', ikm, salt, Buffer.from('Content-Encoding: aes128gcm\0'), 16));
                const nonce = Buffer.from(crypto.hkdfSync('sha256', ikm, salt, Buffer.from('Content-Encoding: nonce\0'), 12));
                const d = crypto.createDecipheriv('aes-128-gcm', cek, nonce);
                d.setAuthTag(ct.subarray(ct.length - 16));
                const plain = Buffer.concat([d.update(ct.subarray(0, ct.length - 16)), d.final()]);
                let end = plain.length - 1;
                while (end >= 0 && plain[end] === 0) end--;
                process.stdout.write(JSON.stringify({ rs, delimiter: plain[end], text: plain.subarray(0, end).toString('utf8') }));
            });
            JS, [
            'uaPrivate' => WebPush::b64urlEncode($uaPrivate),
            'uaPublic'  => WebPush::b64urlEncode($uaPublic),
            'auth'      => WebPush::b64urlEncode($auth),
            'body'      => WebPush::b64urlEncode($body),
        ]);

        $this->assertSame(['rs' => 4096, 'delimiter' => 2, 'text' => $message], $out);
    }

    public function testDesClesDAppareilInvalidesSontRefusees(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebPush::encrypt('x', str_repeat("\x04", 33), random_bytes(16));
    }

    public function testLesClesDUnNavigateurSontLuesStrictement(): void
    {
        $point = "\x04" . random_bytes(64);
        $auth  = random_bytes(16);
        $ok = WebPush::decodeKeys(WebPush::b64urlEncode($point), WebPush::b64urlEncode($auth));
        $this->assertSame(['p256dh' => $point, 'auth' => $auth], $ok);
        // Base64 ordinaire (avec + / =) accepté aussi.
        $this->assertNotNull(WebPush::decodeKeys(base64_encode($point), base64_encode($auth)));

        $this->assertNull(WebPush::decodeKeys(WebPush::b64urlEncode("\x02" . random_bytes(64)), WebPush::b64urlEncode($auth)), 'Point compressé : refusé.');
        $this->assertNull(WebPush::decodeKeys(WebPush::b64urlEncode($point), WebPush::b64urlEncode(random_bytes(15))));
        $this->assertNull(WebPush::decodeKeys('pas*du*base64', WebPush::b64urlEncode($auth)));
        $this->assertNull(WebPush::decodeKeys('', ''));
    }

    // ------------------------------------------------------------------
    // Jeton VAPID (RFC 8292)
    // ------------------------------------------------------------------

    public function testLeJetonVapidEstSigneEnEs256(): void
    {
        $pair  = WebPush::generateKeyPair();
        $token = WebPush::vapidToken('https://fcm.googleapis.com', 1790000000, 'mailto:notifications@exemple.fr', $pair['private_pem']);
        [$h, $c, $s] = explode('.', $token);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::b64urlDecode($h), true));
        $this->assertSame(
            ['aud' => 'https://fcm.googleapis.com', 'exp' => 1790000000, 'sub' => 'mailto:notifications@exemple.fr'],
            json_decode(WebPush::b64urlDecode($c), true)
        );
        $raw = WebPush::b64urlDecode($s);
        $this->assertSame(64, strlen($raw), 'Signature JWS : r‖s, 64 octets, jamais du DER.');

        // openssl : signature remise en DER, vérifiée avec la clé publique.
        $der = self::rawToDer($raw);
        $this->assertSame(1, openssl_verify($h . '.' . $c, $der, WebPush::publicKeyPem($pair['public']), OPENSSL_ALGO_SHA256));

        // Node : vérification indépendante au format IEEE P1363 (r‖s).
        $out = $this->node(<<<'JS'
            const crypto = require('crypto');
            let raw = '';
            process.stdin.on('data', (c) => { raw += c; });
            process.stdin.on('end', () => {
                const i = JSON.parse(raw);
                const pub = Buffer.from(i.pub, 'base64url');
                const key = crypto.createPublicKey({ format: 'jwk', key: {
                    kty: 'EC', crv: 'P-256',
                    x: pub.subarray(1, 33).toString('base64url'),
                    y: pub.subarray(33, 65).toString('base64url'),
                } });
                const [h, c, s] = i.token.split('.');
                const ok = crypto.verify('sha256', Buffer.from(h + '.' + c), { key, dsaEncoding: 'ieee-p1363' }, Buffer.from(s, 'base64url'));
                process.stdout.write(JSON.stringify(ok));
            });
            JS, ['token' => $token, 'pub' => WebPush::b64urlEncode($pair['public'])]);
        $this->assertTrue($out);
    }

    /** r‖s → DER (SEQUENCE de deux INTEGER) : l'inverse de derToRaw, pour openssl_verify. */
    private static function rawToDer(string $raw): string
    {
        $int = static function (string $v): string {
            $v = ltrim($v, "\0");
            if ($v === '' || (ord($v[0]) & 0x80)) {
                $v = "\0" . $v;
            }
            return "\x02" . chr(strlen($v)) . $v;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
        return "\x30" . chr(strlen($seq)) . $seq;
    }

    public function testUneSignatureDerEstRameneeA64Octets(): void
    {
        // r avec octet de signe (33 octets), s court (31 octets) : les deux bornés à 32.
        $r = "\x00\x80" . str_repeat("\x11", 31);
        $s = str_repeat("\x22", 31);
        $der = "\x30" . chr(4 + strlen($r) + strlen($s)) . "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
        $raw = WebPush::derToRaw($der);
        $this->assertSame(64, strlen($raw));
        $this->assertSame("\x80" . str_repeat("\x11", 31), substr($raw, 0, 32));
        $this->assertSame("\x00" . str_repeat("\x22", 31), substr($raw, 32));

        $this->expectException(\RuntimeException::class);
        WebPush::derToRaw("\x31\x00");
    }

    public function testLesClesPemSontRelues(): void
    {
        $pair = WebPush::generateKeyPair();
        $this->assertSame(65, strlen($pair['public']));
        $this->assertSame("\x04", $pair['public'][0]);
        $this->assertNotFalse(openssl_pkey_get_public(WebPush::publicKeyPem($pair['public'])));
        $d = openssl_pkey_get_details(openssl_pkey_get_private($pair['private_pem']))['ec']['d'];
        $rebuilt = openssl_pkey_get_private(WebPush::privateKeyPem($d, $pair['public']));
        $this->assertNotFalse($rebuilt);
        $this->assertSame($pair['public'], "\x04"
            . str_pad(openssl_pkey_get_details($rebuilt)['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad(openssl_pkey_get_details($rebuilt)['ec']['y'], 32, "\0", STR_PAD_LEFT));
    }

    // ------------------------------------------------------------------
    // Liste blanche et audience
    // ------------------------------------------------------------------

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function adresses(): iterable
    {
        yield 'Chrome'               => ['https://fcm.googleapis.com/fcm/send/abc:def', true];
        yield 'Chrome, nouveau'      => ['https://fcm.googleapis.com/wp/abc', true];
        yield 'Firefox'              => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAA', true];
        yield 'Safari'               => ['https://web.push.apple.com/QGuQyavXutnMH', true];
        yield 'Apple, autre hôte'    => ['https://api.push.apple.com/3/device/x', true];
        yield 'Edge (WNS)'           => ['https://wns2-par02p.notify.windows.com/w/?token=BQYAAAB', true];
        yield 'port 443 explicite'   => ['https://fcm.googleapis.com:443/fcm/send/abc', true];
        yield 'http'                 => ['http://fcm.googleapis.com/fcm/send/abc', false];
        yield 'autre port'           => ['https://fcm.googleapis.com:8443/fcm/send/abc', false];
        yield 'identifiants'         => ['https://user:pass@fcm.googleapis.com/fcm/send/abc', false];
        yield 'sous-domaine piège'   => ['https://fcm.googleapis.com.evil.example/x', false];
        yield 'préfixe piège'        => ['https://evilfcm.googleapis.com/x', false];
        yield 'suffixe sans point'   => ['https://evilnotify.windows.com/w/', false];
        yield 'domaine Apple piège'  => ['https://push.apple.com.evil.example/x', false];
        yield 'réseau interne'       => ['https://127.0.0.1/x', false];
        yield 'nom interne'          => ['https://qdrant:6333/collections', false];
        yield 'métadonnées cloud'    => ['https://169.254.169.254/latest/meta-data/', false];
        yield 'retour à la ligne'    => ["https://fcm.googleapis.com/x\nHost: interne", false];
        yield 'vide'                 => ['', false];
        yield 'trop longue'          => ['https://fcm.googleapis.com/' . str_repeat('a', 2100), false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adresses')]
    public function testSeulsLesServicesDeNotificationConnusSontAcceptes(string $url, bool $attendu): void
    {
        $this->assertSame($attendu, WebPush::isAllowedEndpoint($url));
    }

    public function testLAudienceEstLOrigineDuService(): void
    {
        $this->assertSame('https://fcm.googleapis.com', WebPush::audience('https://fcm.googleapis.com/fcm/send/abc'));
        $this->assertSame('https://web.push.apple.com', WebPush::audience('https://WEB.push.apple.com/QGuQ'));
    }

    public function testCeQueDitUnStatutDuServiceDeNotification(): void
    {
        foreach ([200, 201, 202] as $ok) {
            $this->assertSame('ok', WebPush::outcome($ok));
        }
        $this->assertSame('gone', WebPush::outcome(404));
        $this->assertSame('gone', WebPush::outcome(410));
        // Un refus du jeton n'efface rien d'emblée : un défaut de notre côté
        // effacerait sinon les inscriptions de tout le monde au premier envoi.
        foreach ([0, 400, 401, 403, 413, 429, 500, 503] as $code) {
            $this->assertSame('retry', WebPush::outcome($code), "Statut $code");
        }
    }

    // ------------------------------------------------------------------
    // Ce que dit une notification
    // ------------------------------------------------------------------

    private static function tache(array $surcharge = []): array
    {
        return $surcharge + ['id' => 7, 'name' => 'Point du lundi', 'status' => 'active', 'pause_reason' => ''];
    }

    public function testUnResultatArriveOuvreSaConversation(): void
    {
        $m = TaskNotifier::message(self::tache(), ScheduledTaskRun::DONE, '', 0, 42);
        $this->assertSame(['title' => 'Point du lundi', 'body' => 'Le résultat est arrivé.', 'url' => '/chat/42', 'tag' => 'mia-task-7'], $m);

        $this->assertSame('Résultat prêt — 1 action attend votre validation.', TaskNotifier::message(self::tache(), 'done', '', 1, 42)['body']);
        $this->assertSame('Résultat prêt — 3 actions attendent votre validation.', TaskNotifier::message(self::tache(), 'done', '', 3, 42)['body']);
    }

    public function testUnEchecEtUnePauseSontDits(): void
    {
        $this->assertSame('L’exécution n’a pas abouti.', TaskNotifier::message(self::tache(), 'failed', 'model_error', 0, 42)['body']);
        $pause = TaskNotifier::message(self::tache(['status' => 'paused', 'pause_reason' => ScheduledTask::PAUSE_FAILURES]), 'failed', 'model_error', 0, 42);
        $this->assertSame('Trois échecs d’affilée : la tâche est en pause.', $pause['body']);
        // Sans conversation (échec avant tout message) : la page de la tâche.
        $this->assertSame('/settings/schedules/7/edit', TaskNotifier::message(self::tache(), 'failed', 'model_unavailable', 0, null)['url']);
    }

    public function testUneExecutionSauteeOuUnePauseMenentALaTache(): void
    {
        $quota = TaskNotifier::message(self::tache(), 'skipped', 'quota', 0, null);
        $this->assertSame('Exécution sautée : quota de crédits atteint.', $quota['body']);
        $this->assertSame('/settings/schedules/7/edit', $quota['url']);
        $this->assertSame('Exécution sautée : budget IA de l’organisation atteint.', TaskNotifier::message(self::tache(), 'skipped', 'budget', 0, null)['body']);
        $this->assertSame('Tâche mise en pause : assistant indisponible.', TaskNotifier::message(self::tache(), 'skipped', ScheduledTask::PAUSE_ASSISTANT, 0, null)['body']);
    }

    public function testRienNEstDitQuandRienNeLeDemande(): void
    {
        foreach ([['skipped', 'still_running'], ['skipped', ScheduledTask::PAUSE_ACCOUNT], ['cancelled', ''], ['missed', 'late'], ['duplicate', '']] as [$status, $reason]) {
            $this->assertNull(TaskNotifier::message(self::tache(), $status, $reason, 0, 42), "$status / $reason");
        }
    }

    public function testLaNotificationSuitLaLangueDeLaPersonne(): void
    {
        I18n::use('en');
        $this->assertSame('The result is in.', TaskNotifier::message(self::tache(), 'done', '', 0, 42)['body']);
        $this->assertSame('Result ready — 2 actions await your approval.', TaskNotifier::message(self::tache(), 'done', '', 2, 42)['body']);
    }

    public function testLeNomDUnAppareilEstLisible(): void
    {
        $this->assertSame('Chrome · macOS', PushSubscription::deviceLabel('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36'));
        $this->assertSame('Safari · iPhone', PushSubscription::deviceLabel('Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1'));
        $this->assertSame('Firefox · Windows', PushSubscription::deviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'));
        $this->assertSame('Edge · Windows', PushSubscription::deviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36 Edg/141.0'));
        $this->assertSame('Samsung Internet · Android', PushSubscription::deviceLabel('Mozilla/5.0 (Linux; Android 15; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0 Mobile Safari/537.36'));
        $this->assertSame('Navigateur', PushSubscription::deviceLabel(''));
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeServiceWorkerNeFaitQueLesNotifications(): void
    {
        $sw = self::src('public/sw.js');
        $this->assertStringContainsString("addEventListener('push'", $sw);
        $this->assertStringContainsString("addEventListener('notificationclick'", $sw);
        // Aucun cache, aucune interposition entre l'application et le réseau.
        $this->assertStringNotContainsString("addEventListener('fetch'", $sw);
        $this->assertStringNotContainsString('caches.', $sw);
        // Une notification ne fait jamais sortir de l'application.
        $this->assertStringContainsString('url.origin === self.location.origin', $sw);
        $this->assertStringContainsString('sameOriginPath(event.notification.data', $sw);
    }

    public function testLaMigrationCreeLaTableEtLaCase(): void
    {
        $sql = self::src('database/migrations/084_push_notifications.sql');
        $this->assertStringContainsString('ADD COLUMN notify TINYINT(1) NOT NULL DEFAULT 1', $sql);
        $this->assertStringContainsString('CREATE TABLE push_subscriptions', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_push_endpoint (endpoint_hash)', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testLesClassesSontChargeesPartout(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $src = self::src($liste);
            foreach (['src/Push/WebPush.php', 'src/Models/PushSubscription.php', 'src/Schedule/TaskNotifier.php'] as $classe) {
                $this->assertStringContainsString($classe, $src, "$liste : $classe absent.");
            }
        }
        $this->assertStringContainsString('src/Controllers/PushController.php', self::src('config/classes.php'));
    }

    public function testLesRoutesLitteralesPrecedentLaRouteParametree(): void
    {
        $index = self::src('public/index.php');
        $param = strpos($index, "\$router->post('/settings/push/{id}/delete'");
        $this->assertIsInt($param);
        foreach (['subscribe', 'unsubscribe', 'test'] as $route) {
            $pos = strpos($index, "\$router->post('/settings/push/$route'");
            $this->assertIsInt($pos, "Route $route absente.");
            $this->assertLessThan($param, $pos);
        }
    }

    public function testLInscriptionNAccepteQueLesServicesConnus(): void
    {
        $ctrl = self::src('src/Controllers/PushController.php');
        $subscribe = substr($ctrl, (int)strpos($ctrl, 'function subscribe('), 2000);
        $this->assertStringContainsString('WebPush::isAllowedEndpoint($endpoint)', $subscribe);
        $this->assertStringContainsString('WebPush::decodeKeys($p256dh, $auth)', $subscribe);
        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $subscribe);
        // L'écran ne voit jamais l'adresse d'envoi ni les clés.
        $devices = substr($ctrl, (int)strpos($ctrl, 'function devices('));
        $this->assertStringNotContainsString("'endpoint' =>", $devices);
        $this->assertStringNotContainsString("'p256dh'", $devices);

        // L'envoi : aucune redirection, aucun proxy, https seulement.
        $push = self::src('src/Push/WebPush.php');
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION  => false', $push);
        $this->assertStringContainsString("CURLOPT_PROXY           => ''", $push);
        $this->assertStringContainsString('CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS', $push);
        $this->assertStringContainsString('if ($ua === null || !self::isAllowedEndpoint($endpoint))', $push);
    }

    public function testUneExecutionProgrammeePrevientApresSonIssue(): void
    {
        $run = self::src('src/Models/ScheduledTaskRun.php');
        $finished = substr($run, (int)strpos($run, 'function onJobFinished('), 3000);
        $this->assertStringContainsString('is_manual', $finished);
        $this->assertLessThan(
            strpos($finished, 'TaskNotifier::runRecorded('),
            strpos($finished, 'ScheduledTask::recordOutcome('),
            'Après recordOutcome : une pause pour trois échecs se dit aussi.'
        );

        $sched = self::src('src/Schedule/Scheduler.php');
        $due = substr($sched, (int)strpos($sched, 'function processDue('), 2600);
        // Après la transaction : un envoi réseau n'y tient aucun verrou.
        $this->assertLessThan(strpos($due, 'TaskNotifier::runRecorded('), strpos($due, '$db->commit();' . "\n" . '        } catch'));

        $notifier = self::src('src/Schedule/TaskNotifier.php');
        $this->assertStringContainsString('if ($manual) {', $notifier, '« Exécuter maintenant » ne prévient pas : la personne regarde.');
        $this->assertStringContainsString("empty(\$task['notify'])", $notifier);
        $this->assertStringContainsString('I18n::forUser($user)', $notifier);
    }

    public function testMeprevenirEstUneCaseDuFormulaireActiveParDefaut(): void
    {
        $tpl = self::src('templates/settings/schedule-form.php');
        $this->assertStringContainsString('name="notify" value="1"', $tpl);
        $ctrl = self::src('src/Controllers/ScheduledTaskController.php');
        $this->assertStringContainsString("'notify'      => true,", $ctrl, 'Défaut du formulaire : oui.');
        $this->assertStringContainsString("+ ['notify' => '1']", $ctrl, 'Carte d’avant les notifications, ajustée : oui.');
        $this->assertStringContainsString("+ ['notify' => '1']", self::src('src/Controllers/ChatController.php'));
        $this->assertStringContainsString("'notify'        => '1',", self::src('src/Agent/Tools/ScheduleTools.php'));

        $allowed = ['profiles' => [], 'llms' => ['openai::gpt-5.6' => 'x'], 'sources' => []];
        $base = ['instruction' => 'Le point.', 'llm' => 'openai::gpt-5.6', 'freq' => 'daily', 'time' => '08:00'];
        $this->assertFalse(ScheduledTask::normalizeInput($base, $allowed)['notify'], 'Case absente = décochée.');
        $data = ScheduledTask::normalizeInput($base + ['notify' => '1'], $allowed);
        $this->assertTrue($data['notify']);
        $this->assertSame('1', ScheduledTask::toInput($data)['notify']);
    }

    public function testLaPageEtLeScriptPortentLaCarteDesNotifications(): void
    {
        $tpl = self::src('templates/settings/schedules.php');
        foreach (['id="schedPush"', 'data-vapid-key=', 'id="schedPushOn"', 'id="schedPushOff"', 'id="schedPushTest"', 'id="schedPushDevices"'] as $m) {
            $this->assertStringContainsString($m, $tpl, "Page : $m absent.");
        }
        $js = self::src('public/assets/js/schedules.js');
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js', { scope: '/' })", $js);
        $this->assertStringContainsString('userVisibleOnly: true', $js);
        $this->assertStringContainsString('Notification.requestPermission()', $js);
        $this->assertStringContainsString("localStorage.setItem(OWNER_KEY, userId)", $js);
        // Une inscription faite avec une autre clé est défaite, pas gardée.
        $this->assertStringContainsString('sub.options.applicationServerKey) !== key', $js);
    }

    public function testUnNavigateurNeRecoitQueLesNotificationsDuCompteConnecte(): void
    {
        $meta = self::src('templates/partials/head-meta.php');
        $this->assertStringContainsString('<meta name="mia-user"', $meta);
        $menu = self::src('public/assets/js/user-menu.js');
        $this->assertStringContainsString("var OWNER_KEY = 'mia.push.owner';", $menu);
        $this->assertStringContainsString("closest('a[href=\"/auth/logout\"]')", $menu);
        $this->assertStringContainsString("if (inscrit !== '' && me !== '' && inscrit !== me)", $menu);
        $this->assertStringContainsString("fetch('/settings/push/unsubscribe'", $menu);
        // Le retrait par l'adresse vaut quel que soit le compte : posséder
        // l'adresse, c'est être l'appareil.
        $this->assertStringContainsString('WHERE endpoint_hash = ?', self::src('src/Models/PushSubscription.php'));
    }

    public function testLaSauvegardeNEmporteNiLesAppareilsNiLesCles(): void
    {
        $this->assertContains('push_subscriptions', BackupService::EXCLUDED_TABLES);
        $this->assertContains(WebPush::SETTING_PUBLIC, BackupService::EXCLUDED_SETTINGS_KEYS);
        $this->assertContains(WebPush::SETTING_PRIVATE, BackupService::EXCLUDED_SETTINGS_KEYS);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationEnParle(): void
    {
        $page = self::src('docs/user/fonctionnalites/conversations/programmer-une-tache.md');
        $this->assertStringContainsString('## Être prévenu sur ses appareils', $page);
        $this->assertStringContainsString('le contenu de la réponse**', $page, 'La page dit ce qu’une notification ne montre jamais.');
        $this->assertStringContainsString('Notifications sur vos appareils', self::src('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('fcm.googleapis.com', self::src('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('**Les notifications.**', self::src('docs/user/admin/outils/regler-les-taches-programmees.md'));
        $this->assertStringContainsString('id: taches-notifications', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('084_push_notifications.sql', self::src('CLAUDE.md'));
    }

    public function testLesStylesDeLaCarteSontAuPremierNiveau(): void
    {
        $css = self::src('public/assets/css/main.css');
        foreach (['.sched-push-bar [hidden] { display: none !important; }', '.sched-push-devices[hidden] { display: none !important; }', '.sched-push-help[hidden] { display: none !important; }'] as $regle) {
            $this->assertStringContainsString($regle, $css);
        }
    }
}
