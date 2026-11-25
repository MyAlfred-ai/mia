<?php

declare(strict_types=1);

/**
 * Déclencheur « email reçu » en mode PUSH — SOURCE UNIQUE.
 *
 * Sans lui, un workflow qui démarre sur un email vérifie la boîte chaque
 * minute (WorkflowTriggers). Avec lui, le fournisseur prévient MIA dès
 * qu'un email arrive :
 *  - Outlook : abonnement Microsoft Graph sur la boîte de réception
 *    (POST /subscriptions), qui poste à /hooks/mail/outlook. Rien à régler
 *    côté Microsoft : la permission de lecture du connecteur suffit.
 *  - Gmail : users.watch vers un topic Google Cloud Pub/Sub que
 *    l'administrateur a créé ; un abonnement push de ce topic poste à
 *    /hooks/mail/gmail/<jeton>.
 *
 * LA NOTIFICATION NE FAIT QUE RÉVEILLER la vérification existante
 * (WorkflowTriggers::runWatchJob) : mêmes filtres, même ligne de base, un
 * email ne lance qu'une exécution. Rien n'est pris dans la notification —
 * les messages sont relus avec le compte de la personne —, si bien qu'une
 * notification forgée ne peut, au pire, que déclencher une vérification.
 *
 * Ce qui reste périodique, à dessein :
 *  - le renouvellement : Graph ≤ 7 jours (ici 3, renouvelés chaque jour),
 *    Gmail 7 jours (renouvelé chaque jour, comme Google le conseille) ;
 *  - une vérification de secours toutes les heures (WATCH_PUSH_SEC) : les
 *    deux fournisseurs disent qu'une notification peut se perdre ;
 *  - chaque minute si le push n'est pas en service (adresse non publique,
 *    Gmail sans topic, abonnement en erreur) ;
 *  - une seconde vérification 90 s après chaque notification : un message
 *    annoncé n'est pas toujours lisible dans la seconde.
 */
final class MailPush
{
    public const PROVIDERS = ['gmail', 'outlook'];

    public const SETTING_ENABLED     = 'mail_push_enabled';
    public const SETTING_GMAIL_TOPIC = 'gmail_push_topic';
    public const SETTING_GMAIL_TOKEN = 'gmail_push_token_enc';
    /** Dernière synchronisation des abonnements (UTC). */
    public const SETTING_SYNC_AT     = 'mail_push_sync_at';

    /** Durée demandée pour un abonnement Graph (au plus 10 080 minutes pour un message). */
    public const OUTLOOK_LIFETIME_SEC = 3 * 86400;
    /** Renouvelé quand il lui reste moins que ceci. */
    public const OUTLOOK_RENEW_BEFORE_SEC = 2 * 86400;
    /** Graph : jamais deux renouvellements à moins de 10 minutes (incohérences d'état). */
    public const OUTLOOK_RENEW_GAP_SEC = 600;
    /** Gmail : users.watch rappelé chaque jour (valable 7 jours). */
    public const GMAIL_RENEW_SEC = 86400;
    /** Après un échec de mise en place, nouvel essai dans… */
    public const RETRY_SEC = 3600;
    /** Synchronisation des abonnements par le planificateur. */
    public const SYNC_EVERY_SEC = 900;
    /** Après une notification, nouvelle vérification dans… */
    public const RECHECK_SEC = 90;
    /** Corps d'une notification lu au plus. */
    public const BODY_MAX = 262144;
    public const GMAIL_TOKEN_PREFIX = 'mgp_';

    /** @var (callable(string, int): ?object)|null */
    private static $services = null;
    private static ?string $baseUrl = null;

    // ==================================================================
    // Disponibilité
    // ==================================================================

    public static function isEnabled(): bool
    {
        return Settings::get(self::SETTING_ENABLED, '1') === '1';
    }

    /**
     * Adresse publique de l'instance, ou null : les fournisseurs exigent du
     * HTTPS joignable depuis Internet — jamais un poste local ni un nom
     * interne. Pure.
     */
    public static function publicBaseFrom(string $appUrl): ?string
    {
        $parts = parse_url(trim($appUrl));
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || !str_contains($host, '.') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        foreach (['.localhost', '.local', '.test', '.internal', '.lan', '.home', '.example', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return null;
            }
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        return 'https://' . $host . $port . $path;
    }

    public static function publicBase(): ?string
    {
        return self::publicBaseFrom(self::$baseUrl ?? (defined('APP_URL') ? (string)APP_URL : ''));
    }

    /** Tests : adresse de l'instance de substitution (null = APP_URL). */
    public static function overrideBaseUrl(?string $url): void
    {
        self::$baseUrl = $url;
    }

    public static function outlookUrl(): ?string
    {
        $base = self::publicBase();
        return $base !== null ? $base . '/hooks/mail/outlook' : null;
    }

    /** Topic Pub/Sub : « projects/<projet>/topics/<topic> ». Pure. */
    public static function validTopic(string $topic): bool
    {
        return (bool)preg_match('#^projects/[a-z][a-z0-9-]{4,28}[a-z0-9]/topics/[A-Za-z][A-Za-z0-9._~%+-]{2,254}$#', $topic);
    }

    public static function gmailTopic(): string
    {
        $t = trim((string)Settings::get(self::SETTING_GMAIL_TOPIC, ''));
        return self::validTopic($t) ? $t : '';
    }

    public static function validGmailToken(string $token): bool
    {
        return (bool)preg_match('/^' . self::GMAIL_TOKEN_PREFIX . '[A-Za-z0-9_-]{43}$/', $token);
    }

    /** Jeton secret de l'adresse Pub/Sub, créé au premier besoin (ou remplacé : `$renew`). */
    public static function gmailToken(bool $renew = false): string
    {
        if (!$renew) {
            $enc = (string)Settings::get(self::SETTING_GMAIL_TOKEN, '');
            if ($enc !== '') {
                try {
                    $token = Encryption::decrypt($enc);
                    if (self::validGmailToken($token)) {
                        return $token;
                    }
                } catch (\Throwable) {
                    // illisible : un nouveau jeton le remplace
                }
            }
        }
        $token = self::GMAIL_TOKEN_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Settings::set(self::SETTING_GMAIL_TOKEN, Encryption::encrypt($token));
        return $token;
    }

    public static function gmailUrl(): ?string
    {
        $base = self::publicBase();
        return $base !== null ? $base . '/hooks/mail/gmail/' . self::gmailToken() : null;
    }

    /**
     * Pourquoi le push n'est pas possible pour ce fournisseur sur cette
     * instance (null : il l'est). Texte pour l'écran.
     */
    public static function unavailableReason(string $provider): ?string
    {
        if (!self::isEnabled()) {
            return t('notifications instantanées désactivées par l’administrateur');
        }
        if (self::publicBase() === null) {
            return t('l’adresse de MIA n’est pas joignable depuis Internet en HTTPS');
        }
        if ($provider === 'gmail') {
            if (!UserGmailService::isGloballyEnabled()) {
                return t('Gmail n’est pas proposé');
            }
            if (self::gmailTopic() === '') {
                return t('Gmail n’est pas encore configuré pour les notifications (topic Pub/Sub)');
            }
            return null;
        }
        if ($provider === 'outlook') {
            return UserOutlookService::isGloballyEnabled() ? null : t('Outlook n’est pas proposé');
        }
        return t('fournisseur inconnu');
    }

    /** Le push prévient-il MIA pour cette boîte, maintenant ? */
    public static function isLive(int $userId, string $provider, ?\DateTimeImmutable $now = null): bool
    {
        try {
            if (self::unavailableReason($provider) !== null) {
                return false;
            }
            $row = MailPushSubscription::find($userId, $provider);
            return self::rowIsLive($row, $now ?? self::now());
        } catch (\Throwable) {
            return false;
        }
    }

    /** Pure : un abonnement en service et pas expiré. */
    public static function rowIsLive(?array $row, \DateTimeImmutable $now): bool
    {
        if ($row === null || (string)$row['status'] !== MailPushSubscription::STATUS_ACTIVE) {
            return false;
        }
        $expires = ScheduledTask::fromSqlUtc((string)($row['expires_at'] ?? ''));
        // expires_at effacé = réautorisation demandée par Graph : toujours en
        // service le temps du renouvellement.
        return $expires === null ? (string)$row['provider'] === 'outlook' && (string)($row['external_id'] ?? '') !== '' : $expires > $now;
    }

    /**
     * Ce que l'éditeur dit de la surveillance d'une boîte.
     *
     * @return array{state: string, text: string} state = live | starting | error | polling
     */
    public static function statusFor(int $userId, string $provider): array
    {
        $reason = self::unavailableReason($provider);
        if ($reason !== null) {
            return ['state' => 'polling', 'text' => t('Vérifié chaque minute : {raison}.', ['raison' => $reason])];
        }
        $row = null;
        try {
            $row = MailPushSubscription::find($userId, $provider);
        } catch (\Throwable) {
            // table absente : comme sans abonnement
        }
        if (self::rowIsLive($row, self::now())) {
            return ['state' => 'live', 'text' => t('Instantané : MIA est prévenue dès qu’un email arrive (vérification de secours toutes les heures).')];
        }
        if ($row !== null && (string)$row['status'] === MailPushSubscription::STATUS_ERROR) {
            return ['state' => 'error', 'text' => t('Notifications instantanées indisponibles ({cause}) : vérifié chaque minute.', ['cause' => (string)$row['error']])];
        }
        if ($row === null && self::service($provider, $userId) === null) {
            return ['state' => 'polling', 'text' => t('Vérifié chaque minute : {raison}.', [
                'raison' => t('{service} n’est pas relié dans Réglages → Comptes connectés', ['service' => $provider === 'outlook' ? 'Outlook' : 'Gmail']),
            ])];
        }
        return ['state' => 'starting', 'text' => t('Notifications instantanées en cours de mise en place : vérifié chaque minute en attendant.')];
    }

    // ==================================================================
    // Synchronisation des abonnements
    // ==================================================================

    /** Passage du planificateur : une synchronisation complète tous les SYNC_EVERY_SEC. */
    public static function tick(\DateTimeImmutable $now, bool $dispatch = true): void
    {
        try {
            $last = ScheduledTask::fromSqlUtc((string)Settings::get(self::SETTING_SYNC_AT, ''));
            if ($last !== null && $now->getTimestamp() - $last->getTimestamp() < self::SYNC_EVERY_SEC) {
                return;
            }
            Settings::set(self::SETTING_SYNC_AT, ScheduledTask::sqlUtc($now));
            if (self::neededPairs(null) === [] && MailPushSubscription::all() === []) {
                return;
            }
            $jobId = JobQueue::enqueue('workflow', null, null, ['op' => 'mail_push_sync', 'workflow' => ['scheduled' => true]],
                t('Notifications des emails'), 'wf_mailpush_sync');
            if ($dispatch) {
                JobQueue::dispatch($jobId, false);
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] notifications des emails : ' . $e->getMessage());
        }
    }

    /** Un workflow a changé (publié, activé, mis en pause, supprimé) : ses abonnements suivent. */
    public static function syncSoon(int $userId): void
    {
        try {
            $jobId = JobQueue::enqueue('workflow', $userId, null, ['op' => 'mail_push_sync', 'user_id' => $userId, 'workflow' => ['scheduled' => true]],
                t('Notifications des emails'), 'wf_mailpush_sync:' . $userId);
            JobQueue::dispatch($jobId, false);
        } catch (\Throwable $e) {
            error_log('[Workflows] notifications des emails de #' . $userId . ' : ' . $e->getMessage());
        }
    }

    /** Job `mail_push_sync`. */
    public static function runSyncJob(array $payload): array
    {
        $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : null;
        return self::sync($userId !== null && $userId > 0 ? $userId : null, self::now());
    }

    /**
     * Boîtes qu'il faut suivre : une par personne et fournisseur, dès qu'un
     * workflow publié et actif démarre sur un email de cette boîte.
     *
     * @return array<int, list<string>> user_id → fournisseurs
     */
    public static function neededPairs(?int $userId): array
    {
        $sql = 'SELECT user_id, published_json FROM workflows WHERE is_active = 1 AND published_json IS NOT NULL AND watch_next_at IS NOT NULL';
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $def = json_decode((string)$r['published_json'], true);
            $event = is_array($def) ? ($def['start']['event'] ?? null) : null;
            if (is_array($event) && ($event['kind'] ?? '') === 'email') {
                $source = ($event['source'] ?? '') === 'outlook' ? 'outlook' : 'gmail';
                $uid = (int)$r['user_id'];
                if (!in_array($source, $out[$uid] ?? [], true)) {
                    $out[$uid][] = $source;
                }
            }
        }
        return $out;
    }

    /**
     * Met les abonnements d'accord avec les workflows : crée ce qui manque,
     * renouvelle ce qui expire, retire ce qui ne sert plus. Ne lève jamais.
     *
     * @return array{created:int, renewed:int, removed:int, errors:int}
     */
    public static function sync(?int $userId, \DateTimeImmutable $now): array
    {
        $report = ['created' => 0, 'renewed' => 0, 'removed' => 0, 'errors' => 0];
        try {
            $needed = self::neededPairs($userId);
            $rows = [];
            foreach (MailPushSubscription::all($userId) as $row) {
                $rows[(int)$row['user_id']][(string)$row['provider']] = $row;
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] notifications des emails : ' . $e->getMessage());
            return $report;
        }
        foreach ($needed as $uid => $providers) {
            foreach ($providers as $provider) {
                $row = $rows[$uid][$provider] ?? null;
                unset($rows[$uid][$provider]);
                try {
                    $user = User::findById($uid);
                    $svc = ($user && Workflow::canUse($user)) ? self::service($provider, $uid) : null;
                    if (self::unavailableReason($provider) !== null || $svc === null) {
                        // Plus possible (connexion retirée, réglage, adresse) :
                        // la vérification chaque minute reprend.
                        if ($row !== null) {
                            self::drop($row, $svc);
                            $report['removed']++;
                        }
                        continue;
                    }
                    if ($row !== null && (string)$row['status'] === MailPushSubscription::STATUS_ERROR) {
                        $retry = ScheduledTask::fromSqlUtc((string)($row['retry_at'] ?? ''));
                        if ($retry !== null && $retry > $now) {
                            continue;
                        }
                    }
                    $done = $provider === 'outlook' ? self::ensureOutlook($uid, $svc, $row, $now) : self::ensureGmail($uid, $svc, $row, $now);
                    if ($done !== '') {
                        $report[$done]++;
                    }
                } catch (\Throwable $e) {
                    $report['errors']++;
                    error_log('[Workflows] notifications ' . $provider . ' de #' . $uid . ' : ' . $e->getMessage());
                    try {
                        MailPushSubscription::saveError($uid, $provider, $e->getMessage(), $now->modify('+' . self::RETRY_SEC . ' seconds'));
                    } catch (\Throwable) {
                        // rien de plus à faire : le prochain passage réessaiera
                    }
                }
            }
        }
        // Ce qui ne sert plus (workflow mis en pause, supprimé, autre boîte).
        foreach ($rows as $uid => $byProvider) {
            foreach ($byProvider as $provider => $row) {
                try {
                    self::drop($row, self::service($provider, (int)$uid));
                    $report['removed']++;
                } catch (\Throwable $e) {
                    error_log('[Workflows] notifications ' . $provider . ' de #' . $uid . ' (retrait) : ' . $e->getMessage());
                }
            }
        }
        return $report;
    }

    /** @return string '' | created | renewed */
    private static function ensureOutlook(int $uid, object $svc, ?array $row, \DateTimeImmutable $now): string
    {
        $url = (string)self::outlookUrl();
        $id = (string)($row['external_id'] ?? '');
        $active = $row !== null && (string)$row['status'] === MailPushSubscription::STATUS_ACTIVE;
        if ($active && $id !== '' && (string)$row['endpoint'] === $url) {
            $expires = ScheduledTask::fromSqlUtc((string)($row['expires_at'] ?? ''));
            if ($expires !== null && $expires->getTimestamp() - $now->getTimestamp() > self::OUTLOOK_RENEW_BEFORE_SEC) {
                return '';
            }
            $renewed = ScheduledTask::fromSqlUtc((string)($row['renewed_at'] ?? ''));
            if ($renewed !== null && $now->getTimestamp() - $renewed->getTimestamp() < self::OUTLOOK_RENEW_GAP_SEC) {
                return '';
            }
            try {
                $ts = $svc->renewPushSubscription($id, $now->modify('+' . self::OUTLOOK_LIFETIME_SEC . ' seconds'));
                MailPushSubscription::saveActive($uid, 'outlook', [
                    'account' => (string)$row['account'], 'external_id' => $id, 'secret_hash' => (string)$row['secret_hash'],
                    'endpoint' => $url, 'expires' => self::expiry($ts, $now, self::OUTLOOK_LIFETIME_SEC),
                ], $now);
                return 'renewed';
            } catch (\RuntimeException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
                // Disparu chez Microsoft : on le recrée.
            }
        } elseif ($id !== '') {
            // Autre adresse (APP_URL changée) ou abonnement en erreur : on repart d'un neuf.
            try {
                $svc->deletePushSubscription($id);
            } catch (\RuntimeException) {
                // déjà parti, ou plus d'accès : il expirera de lui-même
            }
        }
        $secret = bin2hex(random_bytes(32));
        $expires = $now->modify('+' . self::OUTLOOK_LIFETIME_SEC . ' seconds');
        try {
            $sub = $svc->createPushSubscription($url, $secret, $expires);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 409) {
                throw $e;
            }
            // Un abonnement resté chez Microsoft sans trace ici : on le retire, puis on recrée.
            foreach ($svc->pushSubscriptionsFor($url) as $old) {
                $svc->deletePushSubscription($old);
            }
            $sub = $svc->createPushSubscription($url, $secret, $expires);
        }
        if ((string)$sub['id'] === '') {
            throw new \RuntimeException(t('Microsoft n’a pas créé l’abonnement.'));
        }
        MailPushSubscription::saveActive($uid, 'outlook', [
            'account' => '', 'external_id' => (string)$sub['id'], 'secret_hash' => hash('sha256', $secret),
            'endpoint' => $url, 'expires' => self::expiry($sub['expires'] ?? null, $now, self::OUTLOOK_LIFETIME_SEC),
        ], $now);
        return 'created';
    }

    /** @return string '' | created | renewed */
    private static function ensureGmail(int $uid, object $svc, ?array $row, \DateTimeImmutable $now): string
    {
        $topic = self::gmailTopic();
        $active = $row !== null && (string)$row['status'] === MailPushSubscription::STATUS_ACTIVE;
        if ($active && (string)$row['endpoint'] === $topic && (string)$row['account'] !== '') {
            $renewed = ScheduledTask::fromSqlUtc((string)($row['renewed_at'] ?? ''));
            if ($renewed !== null && $now->getTimestamp() - $renewed->getTimestamp() < self::GMAIL_RENEW_SEC) {
                return '';
            }
        }
        $account = $active && (string)$row['account'] !== '' ? (string)$row['account'] : $svc->mailboxAddress();
        if ($account === '') {
            throw new \RuntimeException(t('Gmail n’a pas donné l’adresse de la boîte.'));
        }
        $res = $svc->startPushWatch($topic);
        MailPushSubscription::saveActive($uid, 'gmail', [
            'account' => $account, 'external_id' => '', 'secret_hash' => '', 'endpoint' => $topic,
            'expires' => self::expiry($res['expires'] ?? null, $now, 7 * 86400),
        ], $now);
        return $active ? 'renewed' : 'created';
    }

    /** Retire un abonnement : chez le fournisseur si possible, puis ici. */
    private static function drop(array $row, ?object $svc): void
    {
        if ($svc !== null) {
            try {
                if ((string)$row['provider'] === 'outlook' && (string)($row['external_id'] ?? '') !== '') {
                    $svc->deletePushSubscription((string)$row['external_id']);
                } elseif ((string)$row['provider'] === 'gmail' && (string)$row['status'] === MailPushSubscription::STATUS_ACTIVE) {
                    $svc->stopPushWatch();
                }
            } catch (\Throwable $e) {
                // L'abonnement expirera de lui-même (3 à 7 jours) ; ses
                // notifications ne réveilleront plus rien.
                error_log('[Workflows] retrait d’un abonnement ' . $row['provider'] . ' : ' . $e->getMessage());
            }
        }
        MailPushSubscription::delete((int)$row['user_id'], (string)$row['provider']);
    }

    private static function expiry(?int $ts, \DateTimeImmutable $now, int $fallbackSec): \DateTimeImmutable
    {
        return $ts !== null && $ts > $now->getTimestamp()
            ? (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'))
            : $now->modify('+' . $fallbackSec . ' seconds');
    }

    /** Le service de la boîte, relié et lisible, ou null. */
    public static function service(string $provider, int $userId): ?object
    {
        if (self::$services !== null) {
            return (self::$services)($provider, $userId);
        }
        if ($provider === 'outlook') {
            $svc = UserOutlookService::isGloballyEnabled() ? UserOutlookPushService::forUser($userId) : null;
        } else {
            $svc = UserGmailService::isGloballyEnabled() ? UserGmailService::forUser($userId) : null;
        }
        return $svc !== null && $svc->canRead() ? $svc : null;
    }

    /** Tests : services de substitution (null = les vrais). */
    public static function overrideServices(?callable $factory): void
    {
        self::$services = $factory;
    }

    // ==================================================================
    // Notifications reçues
    // ==================================================================

    /**
     * Notification Microsoft Graph (pure à la base près). Validation de
     * l'adresse : le jeton reçu est rendu tel quel, en texte. Sinon chaque
     * notification est vérifiée (abonnement connu, clientState) ; une
     * notification de cycle de vie demande un renouvellement.
     *
     * @return array{status:int, type:string, body:string, wake:list<int>, renew:list<int>}
     */
    public static function handleOutlook(array $query, string $body): array
    {
        $out = ['status' => 202, 'type' => 'text/plain; charset=utf-8', 'body' => '', 'wake' => [], 'renew' => []];
        if (isset($query['validationToken'])) {
            $token = is_string($query['validationToken']) ? $query['validationToken'] : '';
            $token = mb_substr((string)preg_replace('/[\x00-\x1F\x7F]+/u', '', $token), 0, 4096);
            return ['status' => 200, 'body' => $token] + $out;
        }
        $data = json_decode($body, true);
        foreach (array_slice((array)(is_array($data) ? ($data['value'] ?? []) : []), 0, 200) as $n) {
            if (!is_array($n)) {
                continue;
            }
            $row = MailPushSubscription::findByExternal('outlook', (string)($n['subscriptionId'] ?? ''));
            $state = (string)($n['clientState'] ?? '');
            if ($row === null || (string)($row['secret_hash'] ?? '') === '' || !hash_equals((string)$row['secret_hash'], hash('sha256', $state))) {
                continue;
            }
            $uid = (int)$row['user_id'];
            $life = (string)($n['lifecycleEvent'] ?? '');
            if ($life === 'reauthorizationRequired' || $life === 'subscriptionRemoved') {
                MailPushSubscription::requestRenew((int)$row['id']);
                $out['renew'][] = $uid;
                if ($life === 'subscriptionRemoved') {
                    // Rien ne prévient plus MIA tant qu'il n'est pas recréé : on vérifie quand même.
                    $out['wake'][] = $uid;
                }
                continue;
            }
            // Message créé, ou notifications perdues (« missed ») : une vérification rattrape tout.
            $out['wake'][] = $uid;
        }
        $out['wake'] = array_values(array_unique($out['wake']));
        $out['renew'] = array_values(array_unique($out['renew']));
        return $out;
    }

    /**
     * Notification Pub/Sub de Gmail : { message: { data: base64url(JSON
     * {emailAddress, historyId}) } }. Le jeton de l'adresse fait foi.
     *
     * @return array{status:int, wake:list<int>}
     */
    public static function handleGmail(string $token, string $body): array
    {
        if (!self::validGmailToken($token) || self::gmailTopic() === '' || !hash_equals(self::gmailToken(), $token)) {
            return ['status' => 404, 'wake' => []];
        }
        $address = self::gmailAddressFrom($body);
        $wake = [];
        foreach ($address !== '' ? MailPushSubscription::forAccount('gmail', $address) : [] as $row) {
            $wake[] = (int)$row['user_id'];
        }
        // 2xx dans tous les cas : un message illisible ne doit pas être reposté sans fin.
        return ['status' => 204, 'wake' => array_values(array_unique($wake))];
    }

    /** Adresse de la boîte dans une notification Pub/Sub (pure). */
    public static function gmailAddressFrom(string $body): string
    {
        $data = json_decode($body, true);
        $b64 = is_array($data) ? (string)($data['message']['data'] ?? '') : '';
        if ($b64 === '') {
            return '';
        }
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);
        $payload = $raw !== false ? json_decode($raw, true) : null;
        $address = is_array($payload) ? mb_strtolower(trim((string)($payload['emailAddress'] ?? ''))) : '';
        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : '';
    }

    /**
     * Un email est (peut-être) arrivé : les workflows de la personne qui
     * surveillent cette boîte vérifient maintenant. Une vérification en
     * cours n'empêche pas d'en prévoir une après elle (l'email a pu arriver
     * pendant), deux en attente se confondent.
     *
     * @return int vérifications prévues
     */
    public static function wake(int $userId, string $provider): int
    {
        $n = 0;
        try {
            MailPushSubscription::touchNotified($userId, $provider, self::now());
            foreach (Workflow::forUser($userId) as $wf) {
                $event = $wf['published']['start']['event'] ?? null;
                if (!$wf['is_active'] || !is_array($event) || ($event['kind'] ?? '') !== 'email'
                    || (($event['source'] ?? '') === 'outlook' ? 'outlook' : 'gmail') !== $provider) {
                    continue;
                }
                $jobId = JobQueue::enqueue('workflow', $userId, null, [
                    'op' => 'watch', 'workflow_id' => (int)$wf['id'], 'workflow' => ['scheduled' => true], 'push' => true,
                ], mb_substr(t('Surveillance') . ' — ' . $wf['name'], 0, 60), 'wf_watch:' . $wf['id'], true);
                JobQueue::dispatch($jobId, false);
                // Et une seconde vérification peu après : un fournisseur peut
                // prévenir avant que le message ne soit lisible partout.
                Workflow::bringWatchForward((int)$wf['id'], self::now()->modify('+' . self::RECHECK_SEC . ' seconds'));
                $n++;
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] réveil des workflows de #' . $userId . ' : ' . $e->getMessage());
        }
        return $n;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
