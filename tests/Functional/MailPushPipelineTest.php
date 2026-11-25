<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Déclencheur « email reçu » sur la vraie base : abonnements push (création,
 * renouvellement, conflit, retrait, erreur), notifications reçues de
 * Microsoft Graph et de Pub/Sub, réveil des vérifications, filtres appliqués
 * au message, garde sur l'heure de réception. Les fournisseurs sont des
 * doublures (aucun réseau).
 */
final class MailPushPipelineTest extends DatabaseTestCase
{
    private array $reglagesAvant = [];
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        foreach ([Workflow::SETTING_ENABLED, MailPush::SETTING_ENABLED, MailPush::SETTING_GMAIL_TOPIC, MailPush::SETTING_GMAIL_TOKEN,
            MailPush::SETTING_SYNC_AT, 'user_gmail_enabled', 'user_outlook_enabled', 'job_worker_heartbeat_at'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        Settings::set(MailPush::SETTING_ENABLED, '1');
        Settings::set(MailPush::SETTING_GMAIL_TOPIC, '');
        Settings::set(MailPush::SETTING_SYNC_AT, '');
        Settings::set('user_gmail_enabled', '1');
        Settings::set('user_outlook_enabled', '1');
        JobQueue::markWorkerAlive();
        MailPush::overrideBaseUrl('https://mia.exemple.fr');
        $this->userId = $this->insertUser(7, 'creator');
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        MailPush::overrideBaseUrl(null);
        MailPush::overrideServices(null);
        if (self::$pdo !== null) {
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, (string)($valeur ?? ''));
            }
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------

    private static function now(string $mod = ''): \DateTimeImmutable
    {
        $d = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $mod !== '' ? $d->modify($mod) : $d;
    }

    private function publie(string $source, array $mail = [], string $query = ''): array
    {
        $def = WorkflowSpec::normalize([
            'start' => ['event' => ['kind' => 'email', 'source' => $source, 'query' => $query, 'timezone' => 'Europe/Paris', 'filters' => ['mail' => $mail]]],
            'nodes' => [['key' => 'trier', 'type' => 'step', 'title' => 'Trier', 'instruction' => 'Trie @{evenement}.', 'output' => ['key' => 'tri', 'name' => 'Tri', 'form' => 'text']]],
            'end' => ['pieces' => ['tri']],
        ]);
        $id = Workflow::create($this->userId, 'Courrier ' . $source, $def);
        Workflow::publish($id, $this->userId, self::now());
        return Workflow::findById($id);
    }

    private function ligne(string $sql, array $params = []): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    private function nombre(string $sql, array $params = []): int
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** Doublure d'Outlook : abonnements et boîte de réception. */
    private static function outlook(): object
    {
        return new class {
            public array $calls = [];
            public array $mails = [];
            public ?int $failCreate = null;
            public ?int $failRenew = null;
            public int $n = 0;
            public array $remote = [];
            public function canRead(): bool { return true; }
            public function createPushSubscription(string $url, string $state, \DateTimeImmutable $exp): array
            {
                $this->calls[] = ['create', $url, $state];
                if ($this->failCreate !== null) {
                    $code = $this->failCreate;
                    $this->failCreate = null;
                    throw new \RuntimeException('Refus Graph', $code);
                }
                $id = 'sub-' . (++$this->n);
                $this->remote[$id] = $url;
                return ['id' => $id, 'expires' => $exp->getTimestamp()];
            }
            public function renewPushSubscription(string $id, \DateTimeImmutable $exp): ?int
            {
                $this->calls[] = ['renew', $id];
                if ($this->failRenew !== null) {
                    throw new \RuntimeException('Disparu', $this->failRenew);
                }
                return $exp->getTimestamp();
            }
            public function deletePushSubscription(string $id): void { $this->calls[] = ['delete', $id]; unset($this->remote[$id]); }
            public function pushSubscriptionsFor(string $url): array { $this->calls[] = ['list']; return array_keys(array_filter($this->remote, static fn($u) => $u === $url)); }
            public function watchList(string $q, int $limit): array { $this->calls[] = ['list_inbox', $q]; return $this->mails; }
            public function readMessage(string $id, int $budget): array
            {
                foreach ($this->mails as $m) {
                    if ($m['id'] === $id) {
                        return ['de' => $m['de'], 'a' => $m['a'] ?? '', 'objet' => $m['objet'], 'date' => $m['date'] ?? '', 'contenu' => $m['contenu'] ?? 'Corps', 'pieces_jointes' => $m['pj'] ?? []];
                    }
                }
                throw new \RuntimeException('Introuvable.');
            }
        };
    }

    /** Doublure de Gmail : users.watch et la boîte. */
    private static function gmail(): object
    {
        return new class {
            public array $calls = [];
            public array $mails = [];
            public bool $refuse = false;
            public function canRead(): bool { return true; }
            public function mailboxAddress(): string { $this->calls[] = ['profile']; return 'axel@gmail.com'; }
            public function startPushWatch(string $topic): array
            {
                $this->calls[] = ['watch', $topic];
                if ($this->refuse) {
                    throw new \RuntimeException('Google refuse le topic.');
                }
                return ['history_id' => '1', 'expires' => time() + 7 * 86400];
            }
            public function stopPushWatch(): void { $this->calls[] = ['stop']; }
            public function watchList(string $q, int $limit): array { $this->calls[] = ['list_inbox', $q]; return array_map(static fn($m) => ['id' => $m['id']], $this->mails); }
            public function watchMeta(string $id): array
            {
                $this->calls[] = ['meta', $id];
                foreach ($this->mails as $m) {
                    if ($m['id'] === $id) {
                        return ['id' => $id, 'de' => $m['de'], 'a' => $m['a'] ?? '', 'cc' => '', 'objet' => $m['objet'], 'received' => $m['received']];
                    }
                }
                throw new \RuntimeException('Introuvable.');
            }
            public function readMessage(string $id, int $budget): array
            {
                $this->calls[] = ['read', $id];
                foreach ($this->mails as $m) {
                    if ($m['id'] === $id) {
                        if (!empty($m['panne'])) {
                            throw new \RuntimeException('Gmail injoignable.');
                        }
                        return ['de' => $m['de'], 'a' => $m['a'] ?? '', 'objet' => $m['objet'], 'date' => '', 'contenu' => $m['contenu'] ?? 'Corps'];
                    }
                }
                throw new \RuntimeException('Introuvable.');
            }
        };
    }

    // ------------------------------------------------------------------
    // Abonnements
    // ------------------------------------------------------------------

    public function testOutlookAbonneRenouvelleEtRetire(): void
    {
        $svc = self::outlook();
        MailPush::overrideServices(static fn(string $p, int $u) => $p === 'outlook' ? $svc : null);
        $wf = $this->publie('outlook');

        $this->assertSame(['created' => 1, 'renewed' => 0, 'removed' => 0, 'errors' => 0], MailPush::sync(null, self::now()));
        $this->assertSame(['create', 'https://mia.exemple.fr/hooks/mail/outlook'], array_slice($svc->calls[0], 0, 2));
        $row = MailPushSubscription::find($this->userId, 'outlook');
        $this->assertSame('sub-1', $row['external_id']);
        $this->assertSame(hash('sha256', $svc->calls[0][2]), $row['secret_hash'], 'Seule l’empreinte du secret est gardée.');
        $this->assertTrue(MailPush::isLive($this->userId, 'outlook'));
        $this->assertSame('live', MailPush::statusFor($this->userId, 'outlook')['state']);

        // Rien à faire tant qu'il reste plus de deux jours.
        $this->assertSame(['created' => 0, 'renewed' => 0, 'removed' => 0, 'errors' => 0], MailPush::sync(null, self::now('+1 hour')));
        // À moins de deux jours de l'échéance : prolongé.
        $this->assertSame(1, MailPush::sync(null, self::now('+30 hours'))['renewed']);
        $this->assertSame(['renew', 'sub-1'], end($svc->calls));

        // Le workflow en pause : l'abonnement est retiré chez Microsoft et ici.
        Workflow::setActive($wf['id'], $this->userId, false, self::now());
        $this->assertSame(1, MailPush::sync(null, self::now())['removed']);
        $this->assertSame(['delete', 'sub-1'], end($svc->calls));
        $this->assertNull(MailPushSubscription::find($this->userId, 'outlook'));
    }

    public function testOutlookRecreeUnAbonnementDisparuOuEnDouble(): void
    {
        $svc = self::outlook();
        MailPush::overrideServices(static fn(string $p, int $u) => $svc);
        $this->publie('outlook');
        // Un abonnement resté chez Microsoft sans trace ici : 409, retiré puis recréé.
        $svc->remote['ancien'] = 'https://mia.exemple.fr/hooks/mail/outlook';
        $svc->failCreate = 409;
        $this->assertSame(1, MailPush::sync(null, self::now())['created']);
        $this->assertContains(['delete', 'ancien'], $svc->calls);
        $this->assertSame('sub-1', MailPushSubscription::find($this->userId, 'outlook')['external_id']);

        // Disparu (404 au renouvellement) : recréé.
        $svc->failRenew = 404;
        $this->assertSame(1, MailPush::sync(null, self::now('+2 days'))['created']);
        $this->assertSame('sub-2', MailPushSubscription::find($this->userId, 'outlook')['external_id']);
    }

    public function testUnEchecSeDitEtSeRetenteUneHeurePlusTard(): void
    {
        $svc = self::outlook();
        MailPush::overrideServices(static fn(string $p, int $u) => $svc);
        $this->publie('outlook');
        $svc->failCreate = 403;
        $this->assertSame(1, MailPush::sync(null, self::now())['errors']);
        $row = MailPushSubscription::find($this->userId, 'outlook');
        $this->assertSame('error', $row['status']);
        $this->assertSame('Refus Graph', $row['error']);
        $this->assertFalse(MailPush::isLive($this->userId, 'outlook'));
        $st = MailPush::statusFor($this->userId, 'outlook');
        $this->assertSame('error', $st['state']);
        $this->assertStringContainsString('vérifié chaque minute', $st['text']);
        $this->assertSame(0, MailPush::sync(null, self::now('+10 minutes'))['created'], 'Pas avant l’heure.');
        $this->assertSame(1, MailPush::sync(null, self::now('+61 minutes'))['created']);
    }

    public function testSansAdressePubliqueRienNeSAbonne(): void
    {
        $svc = self::outlook();
        MailPush::overrideServices(static fn(string $p, int $u) => $svc);
        MailPush::overrideBaseUrl('http://localhost:8080');
        $this->publie('outlook');
        $this->assertSame(0, MailPush::sync(null, self::now())['created']);
        $this->assertSame([], $svc->calls);
        $this->assertSame('polling', MailPush::statusFor($this->userId, 'outlook')['state']);

        // Coupé par l'administrateur : pareil.
        MailPush::overrideBaseUrl('https://mia.exemple.fr');
        Settings::set(MailPush::SETTING_ENABLED, '0');
        $this->assertSame(0, MailPush::sync(null, self::now())['created']);
        $this->assertStringContainsString('désactivées', MailPush::statusFor($this->userId, 'outlook')['text']);
    }

    public function testGmailSurveilleLeTopicEtSeRenouvelleChaqueJour(): void
    {
        $svc = self::gmail();
        MailPush::overrideServices(static fn(string $p, int $u) => $p === 'gmail' ? $svc : null);
        $this->publie('gmail');
        $this->assertSame(0, MailPush::sync(null, self::now())['created'], 'Sans topic, Gmail reste vérifié chaque minute.');
        $this->assertStringContainsString('topic Pub/Sub', MailPush::statusFor($this->userId, 'gmail')['text']);

        Settings::set(MailPush::SETTING_GMAIL_TOPIC, 'projects/mon-projet/topics/mia-gmail');
        $this->assertSame(1, MailPush::sync(null, self::now())['created']);
        $this->assertContains(['watch', 'projects/mon-projet/topics/mia-gmail'], $svc->calls);
        $this->assertSame('axel@gmail.com', MailPushSubscription::find($this->userId, 'gmail')['account']);
        $this->assertSame(0, MailPush::sync(null, self::now('+2 hours'))['renewed']);
        $this->assertSame(1, MailPush::sync(null, self::now('+25 hours'))['renewed']);

        $svc->refuse = true;
        $this->assertSame(1, MailPush::sync(null, self::now('+50 hours'))['errors']);
        $this->assertSame('Google refuse le topic.', MailPushSubscription::find($this->userId, 'gmail')['error']);
    }

    // ------------------------------------------------------------------
    // Notifications reçues
    // ------------------------------------------------------------------

    public function testGraphValideLAdresseEtReveilleLaBonnePersonne(): void
    {
        $svc = self::outlook();
        MailPush::overrideServices(static fn(string $p, int $u) => $svc);
        $wf = $this->publie('outlook');
        MailPush::sync(null, self::now());
        $secret = $svc->calls[0][2];

        $v = MailPush::handleOutlook(['validationToken' => "Validation: Token\n"], '');
        $this->assertSame(200, $v['status']);
        $this->assertSame('Validation: Token', $v['body'], 'Le jeton revient tel quel, en texte.');
        $this->assertStringStartsWith('text/plain', $v['type']);

        $notif = static fn(string $state, array $extra = []): string => json_encode(['value' => [
            ['subscriptionId' => 'sub-1', 'clientState' => $state, 'changeType' => 'created', 'resource' => 'Users/x/Messages/y'] + $extra,
        ]]);
        $this->assertSame([], MailPush::handleOutlook([], $notif('faux'))['wake'], 'Mauvais secret : ignorée.');
        $ok = MailPush::handleOutlook([], $notif($secret));
        $this->assertSame(202, $ok['status']);
        $this->assertSame([$this->userId], $ok['wake']);

        $life = MailPush::handleOutlook([], $notif($secret, ['lifecycleEvent' => 'reauthorizationRequired']));
        $this->assertSame([$this->userId], $life['renew']);
        $this->assertSame([], $life['wake']);
        $this->assertNull(MailPushSubscription::find($this->userId, 'outlook')['expires_at'], 'Renouvelé au prochain passage.');
        $this->assertTrue(MailPush::isLive($this->userId, 'outlook'), 'Toujours en service le temps du renouvellement.');

        // Le réveil prévoit UNE vérification par workflow qui surveille cette boîte.
        $this->assertSame(1, MailPush::wake($this->userId, 'outlook'));
        $job = $this->ligne("SELECT * FROM async_jobs WHERE dedup_key = ?", ['wf_watch:' . $wf['id']]);
        $this->assertSame('wfwatch:' . $wf['id'], $job['lane']);
        $this->assertSame(1, MailPush::wake($this->userId, 'outlook'));
        $this->assertSame(1, $this->nombre("SELECT COUNT(*) FROM async_jobs WHERE dedup_key = ?", ['wf_watch:' . $wf['id']]), 'Deux en attente se confondent.');
        // Une vérification EN COURS n'empêche pas d'en prévoir une après elle.
        $this->db()->prepare("UPDATE async_jobs SET status = 'running' WHERE id = ?")->execute([(int)$job['id']]);
        MailPush::wake($this->userId, 'outlook');
        $this->assertSame(2, $this->nombre("SELECT COUNT(*) FROM async_jobs WHERE dedup_key = ?", ['wf_watch:' . $wf['id']]));
        $this->assertSame(0, MailPush::wake($this->userId, 'gmail'), 'Pas pour une autre boîte.');
    }

    public function testPubSubReveilleParLAdresseAvecLeJetonSeulement(): void
    {
        $svc = self::gmail();
        MailPush::overrideServices(static fn(string $p, int $u) => $svc);
        Settings::set(MailPush::SETTING_GMAIL_TOPIC, 'projects/mon-projet/topics/mia-gmail');
        $this->publie('gmail');
        MailPush::sync(null, self::now());
        $token = MailPush::gmailToken();
        $this->assertStringEndsWith('/hooks/mail/gmail/' . $token, (string)MailPush::gmailUrl());

        $body = json_encode(['message' => ['data' => rtrim(strtr(base64_encode('{"emailAddress":"axel@gmail.com","historyId":"42"}'), '+/', '-_'), '=')]]);
        $this->assertSame(404, MailPush::handleGmail('mgp_' . str_repeat('x', 43), $body)['status']);
        $ok = MailPush::handleGmail($token, $body);
        $this->assertSame(204, $ok['status']);
        $this->assertSame([$this->userId], $ok['wake']);
        $this->assertSame([], MailPush::handleGmail($token, '{}')['wake'], 'Illisible : accusé, rien de réveillé.');

        $nouveau = MailPush::gmailToken(true);
        $this->assertSame(404, MailPush::handleGmail($token, $body)['status'], 'L’ancienne adresse cesse de répondre.');
        $this->assertSame(204, MailPush::handleGmail($nouveau, $body)['status']);
    }

    // ------------------------------------------------------------------
    // Vérification : filtres, heure de réception, pannes
    // ------------------------------------------------------------------

    public function testLesFiltresSontAppliquesAuMessageEtLAncienNeLancePas(): void
    {
        $svc = self::gmail();
        $wf = $this->publie('gmail', ['from' => '@client.fr', 'exclude' => 'noreply', 'subject' => 'devis, commande']);
        $payload = ['workflow_id' => $wf['id']];
        $t = time();
        $svc->mails = [['id' => 'm1', 'de' => 'Paul <paul@client.fr>', 'objet' => 'Devis ancien', 'received' => $t - 7200]];
        $this->assertSame(['baseline' => 1], WorkflowTriggers::runWatchJob($payload, $svc));
        $this->assertSame([['list_inbox', '']], $svc->calls, 'La ligne de base ne lit que les identifiants — la boîte de réception elle-même, pas la recherche.');

        array_unshift($svc->mails,
            ['id' => 'm6', 'de' => 'Paul <paul@client.fr>', 'objet' => 'Nouvelle commande', 'received' => $t + 5],
            ['id' => 'm5', 'de' => 'Robot <noreply@client.fr>', 'objet' => 'Votre devis', 'received' => $t + 4],
            ['id' => 'm4', 'de' => 'Paul <paul@client.fr>', 'objet' => 'Bonjour', 'received' => $t + 3],
            ['id' => 'm3', 'de' => 'Autre <a@autre.fr>', 'objet' => 'Devis', 'received' => $t + 2],
            ['id' => 'm2', 'de' => 'Paul <paul@client.fr>', 'objet' => 'Devis remonté des archives', 'received' => $t - 86400 * 30],
        );
        $svc->calls = [];
        $this->assertSame(['launched' => 1, 'new' => 5], WorkflowTriggers::runWatchJob($payload, $svc));
        $this->assertSame(['gmail:m6'], $this->db()->query('SELECT trigger_ref FROM workflow_runs')->fetchAll(\PDO::FETCH_COLUMN));
        $reads = array_values(array_filter($svc->calls, static fn($c) => $c[0] === 'read'));
        $this->assertSame([['read', 'm6']], $reads, 'Le message n’est lu en entier que s’il passe les en-têtes.');
        $this->assertSame(['launched' => 0, 'new' => 0], WorkflowTriggers::runWatchJob($payload, $svc), 'Écartés : vus, jamais relancés.');
    }

    public function testUnPdfJointEnLigneParAppleMailCompteCommePieceJointe(): void
    {
        $svc = self::gmail();
        $wf = $this->publie('gmail', ['attachment' => true]);
        $payload = ['workflow_id' => $wf['id']];
        $this->assertSame(['baseline' => 0], WorkflowTriggers::runWatchJob($payload, $svc));
        // Ce que rend la lecture complète de Gmail pour un email d'Apple Mail :
        // le PDF porte « Content-Disposition: inline » et un Content-ID.
        $payloadGmail = ['mimeType' => 'multipart/mixed', 'parts' => [
            ['mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode('Voici le devis.'), '+/', '-_'), '=')]],
            ['mimeType' => 'application/pdf', 'filename' => 'devis.pdf', 'headers' => [
                ['name' => 'Content-Disposition', 'value' => 'inline; filename=devis.pdf'], ['name' => 'Content-Id', 'value' => '<A1B2@apple>']],
                'body' => ['attachmentId' => 'ATT1', 'size' => 84000]],
        ]];
        $pj = MailAttachments::forModel(MailAttachments::fromGmailPayload($payloadGmail));
        $svc->mails = [['id' => 'ap1', 'de' => 'Axel <axel@icloud.com>', 'objet' => 'Devis', 'received' => time() + 5, 'pj' => $pj]];
        $svc = new class($svc) {
            public function __construct(private object $inner) {}
            public function __call(string $m, array $a) { return $this->inner->$m(...$a); }
            public function watchList(string $q, int $l): array { return $this->inner->watchList($q, $l); }
            public function watchMeta(string $id): array { return $this->inner->watchMeta($id); }
            public function readMessage(string $id, int $b): array { return $this->inner->readMessage($id, $b) + ['pieces_jointes' => $this->inner->mails[0]['pj']]; }
        };
        $this->assertSame(['launched' => 1, 'new' => 1], WorkflowTriggers::runWatchJob($payload, $svc));
    }

    public function testUnePanneDeLectureNeFaitPasPerdreLEmail(): void
    {
        $svc = self::gmail();
        $wf = $this->publie('gmail');
        $payload = ['workflow_id' => $wf['id']];
        $this->assertSame(['baseline' => 0], WorkflowTriggers::runWatchJob($payload, $svc));
        $svc->mails = [['id' => 'p1', 'de' => 'a@b.fr', 'objet' => 'x', 'received' => time() + 5, 'panne' => true]];
        $this->assertSame(['launched' => 0, 'new' => 1], WorkflowTriggers::runWatchJob($payload, $svc));
        $svc->mails[0]['panne'] = false;
        $this->assertSame(['launched' => 1, 'new' => 1], WorkflowTriggers::runWatchJob($payload, $svc), 'Revenu : lancé.');

        $svc->mails[] = ['id' => 'p2', 'de' => 'a@b.fr', 'objet' => 'y', 'received' => time() + 6, 'panne' => true];
        foreach ([1, 2, 3] as $i) {
            WorkflowTriggers::runWatchJob($payload, $svc);
        }
        $this->assertContains('p2', Workflow::watchState(Workflow::findById($wf['id']))['seen'], 'Écarté au troisième échec, pas rejoué sans fin.');
    }

    public function testOutlookListeLaBoiteDeReceptionEtFiltreLocalement(): void
    {
        $svc = self::outlook();
        $wf = $this->publie('outlook', ['to' => 'factures@acme.fr', 'attachment' => true]);
        $payload = ['workflow_id' => $wf['id']];
        $this->assertSame(['baseline' => 0], WorkflowTriggers::runWatchJob($payload, $svc));
        $t = time();
        $svc->mails = [
            ['id' => 'o3', 'de' => 'A <a@x.fr>', 'a' => 'Factures <factures@acme.fr>', 'objet' => 'Facture', 'received' => $t + 3, 'pieces' => true, 'pj' => [['nom' => 'f.pdf']]],
            ['id' => 'o2', 'de' => 'A <a@x.fr>', 'a' => 'Factures <factures@acme.fr>', 'objet' => 'Sans fichier', 'received' => $t + 2, 'pieces' => false],
            ['id' => 'o1', 'de' => 'A <a@x.fr>', 'a' => 'Marie <marie@acme.fr>', 'objet' => 'Autre', 'received' => $t + 1, 'pieces' => true],
        ];
        $this->assertSame(['launched' => 1, 'new' => 3], WorkflowTriggers::runWatchJob($payload, $svc));
        $this->assertSame(['outlook:o3'], $this->db()->query('SELECT trigger_ref FROM workflow_runs')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(['list_inbox', ''], $svc->calls[0], 'Sans recherche avancée : la boîte de réception, triée par date.');
    }

    public function testLEssaiDesFiltresJugeLesDerniersEmailsSansRienLancer(): void
    {
        $svc = self::gmail();
        $t = time();
        $svc->mails = [
            ['id' => 'e3', 'de' => 'Paul <paul@client.fr>', 'objet' => 'Demande de devis', 'received' => $t - 60, 'contenu' => 'C’est urgent.'],
            ['id' => 'e2', 'de' => 'Robot <noreply@client.fr>', 'objet' => 'Votre devis', 'received' => $t - 120],
            ['id' => 'e1', 'de' => 'Paul <paul@client.fr>', 'objet' => 'Devis', 'received' => $t - 86400 * 40, 'contenu' => 'Rien de pressé.'],
        ];
        $event = WorkflowSpec::normalize(['start' => ['event' => ['kind' => 'email', 'source' => 'gmail',
            'filters' => ['mail' => ['from' => '@client.fr', 'exclude' => 'noreply', 'words' => 'urgent']]]]])['start']['event'];
        $res = WorkflowTriggers::testEmailFilters($event, $svc);
        $this->assertSame([true, false, false], array_column($res['items'], 'match'));
        $this->assertSame('expéditeur écarté par « noreply »', $res['items'][1]['reason']);
        $this->assertSame('sans les mots « urgent »', $res['items'][2]['reason'], 'Un ancien email est jugé aussi : l’essai porte sur les filtres.');
        $this->assertSame(1, $res['matched']);
        $this->assertSame(['list_inbox', ''], $svc->calls[0], 'Toute la boîte de réception, par son libellé, pas seulement les trois derniers jours.');
        $reads = array_column(array_values(array_filter($svc->calls, static fn($c) => $c[0] === 'read')), 1);
        $this->assertSame(['e3', 'e1'], $reads, 'Le message écarté par ses en-têtes n’est pas lu.');
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM workflow_runs'));

        // Sans filtre qui demande le corps : aucune lecture complète.
        $svc->calls = [];
        $event['filters']['mail']['words'] = '';
        $this->assertSame(2, WorkflowTriggers::testEmailFilters($event, $svc)['matched']);
        $this->assertSame([], array_filter($svc->calls, static fn($c) => $c[0] === 'read'));
    }

    public function testUnEssaiALaMainSurUnEmailChoisiRemetLaPieceCompleteEtNeCompteRienDeVu(): void
    {
        $svc = self::outlook();
        $svc->mails = [['id' => 'AAMk-1', 'de' => 'Paul <paul@client.fr>', 'objet' => 'Re: Devis', 'contenu' => 'Pouvez-vous confirmer ?']];
        $wf = $this->publie('outlook', ['from' => '@autre.fr']);
        $user = User::findById($this->userId);
        // Les filtres ne s'appliquent pas à l'essai : la personne a choisi l'email.
        $event = WorkflowTriggers::chosenEmailEvent($wf['published'], $this->userId, 'AAMk-1', $svc);
        $res = WorkflowEngine::launch($wf, $user, [], [], 'manual', null, 'Europe/Paris', $event);
        $this->assertNotNull($res);
        $run = $this->ligne('SELECT trigger_kind, trigger_ref FROM workflow_runs WHERE id = ?', [$res['run_id']]);
        $this->assertSame('manual', $run['trigger_kind']);
        $this->assertNull($run['trigger_ref'], 'Un essai ne marque pas l’email : il peut être rejoué, et la surveillance le traitera s’il arrive vraiment.');
        $piece = json_decode((string)$this->ligne('SELECT content_json FROM workflow_artifacts WHERE run_id = ? AND art_key = ?', [$res['run_id'], WorkflowSpec::EVENT_PIECE])['content_json'], true);
        $this->assertSame('AAMk-1', $piece['message_id']);
        $this->assertSame('paul@client.fr', $piece['fields']['expediteur']);
        $this->assertSame('Re: Devis', $piece['fields']['objet']);
        $this->assertSame('outlook', $piece['service']);

        // Même règle pour un fichier Drive reçu : son identifiant reste dans
        // la pièce (une boucle relit le fichier par lui).
        $defFichier = WorkflowSpec::normalize([
            'start' => ['event' => ['kind' => 'file', 'folder' => 'dossier1']],
            'nodes' => [['key' => 'lire', 'type' => 'step', 'title' => 'Lire', 'instruction' => 'Lis @{evenement}.', 'output' => ['key' => 'resume', 'name' => 'Résumé', 'form' => 'text']]],
            'end' => ['pieces' => ['resume']],
        ]);
        $idFichier = Workflow::create($this->userId, 'Fichiers', $defFichier);
        Workflow::publish($idFichier, $this->userId, self::now());
        $resF = WorkflowEngine::launch(Workflow::findById($idFichier), $user, [], [], 'file', null, 'Europe/Paris',
            ['name' => 'a.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'path' => '', 'text' => 'x', 'drive_id' => 'drv123']);
        $pieceF = json_decode((string)$this->ligne('SELECT content_json FROM workflow_artifacts WHERE run_id = ? AND art_key = ?', [$resF['run_id'], WorkflowSpec::EVENT_PIECE])['content_json'], true);
        $this->assertSame('drv123', $pieceF['drive_id']);

        // Deux essais sur le même email : deux exécutions.
        $this->assertNotNull(WorkflowEngine::launch($wf, $user, [], [], 'manual', null, 'Europe/Paris', $event));
        $this->assertSame(2, $this->nombre('SELECT COUNT(*) FROM workflow_runs WHERE workflow_id = ?', [$wf['id']]));
    }

    public function testUneBoiteQuiPrevientMIANEstPlusVerifieeQuUneFoisParHeure(): void
    {
        $svc = self::outlook();
        MailPush::overrideServices(static fn(string $p, int $u) => $svc);
        $wf = $this->publie('outlook');
        $this->assertSame(60, WorkflowTriggers::watchInterval($wf), 'Sans notification instantanée : chaque minute.');
        MailPush::sync(null, self::now());
        $this->assertSame(WorkflowTriggers::WATCH_PUSH_SEC, WorkflowTriggers::watchInterval(Workflow::findById($wf['id'])));

        // Une notification : une vérification tout de suite, et une seconde peu après.
        $this->db()->prepare('UPDATE workflows SET watch_next_at = ? WHERE id = ?')->execute([ScheduledTask::sqlUtc(self::now('+1 hour')), $wf['id']]);
        MailPush::wake($this->userId, 'outlook');
        $next = ScheduledTask::fromSqlUtc((string)$this->ligne('SELECT watch_next_at FROM workflows WHERE id = ?', [$wf['id']])['watch_next_at']);
        $this->assertLessThanOrEqual(self::now('+' . (MailPush::RECHECK_SEC + 5) . ' seconds')->getTimestamp(), $next->getTimestamp());
        // Jamais reculée.
        $this->db()->prepare('UPDATE workflows SET watch_next_at = ? WHERE id = ?')->execute([ScheduledTask::sqlUtc(self::now('+10 seconds')), $wf['id']]);
        MailPush::wake($this->userId, 'outlook');
        $this->assertLessThanOrEqual(self::now('+11 seconds')->getTimestamp(), ScheduledTask::fromSqlUtc((string)$this->ligne('SELECT watch_next_at FROM workflows WHERE id = ?', [$wf['id']])['watch_next_at'])->getTimestamp());
    }

    public function testLePlanificateurNeSynchroniseQuUnQuartDHeureSurQuatre(): void
    {
        $this->publie('outlook');
        MailPush::tick(self::now(), false);
        $this->assertSame(1, $this->nombre("SELECT COUNT(*) FROM async_jobs WHERE dedup_key = 'wf_mailpush_sync'"));
        $this->db()->exec("DELETE FROM async_jobs");
        MailPush::tick(self::now('+5 minutes'), false);
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM async_jobs'));
        MailPush::tick(self::now('+16 minutes'), false);
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM async_jobs'));
    }
}
