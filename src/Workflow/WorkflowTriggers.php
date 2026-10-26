<?php

declare(strict_types=1);

/**
 * Déclencheurs d'un workflow par ÉVÈNEMENT (start.event de la version
 * publiée) :
 *  - email   : un email arrive dans la boîte Gmail ou Outlook de la personne,
 *              et correspond à son filtre ;
 *  - file    : un fichier apparaît dans un dossier Google Drive ;
 *  - webhook : un autre logiciel appelle l'adresse secrète du workflow
 *              (POST /hooks/workflows/<jeton>, WorkflowHookController) ;
 *  - workflow : un autre workflow de la personne se termine (son livrable
 *              devient la pièce de départ de celui-ci).
 *
 * Boîte mail et dossier sont SURVEILLÉS : le planificateur (tick) enfile,
 * toutes les WATCH_SEC, un job `workflow` op `watch` par workflow à vérifier ;
 * le job liste les nouveautés et lance une exécution par nouveauté, dans
 * l'ordre d'arrivée. Ce qui existait AVANT l'activation ne lance rien (la
 * première vérification pose la ligne de base). Un même email ne lance
 * qu'une exécution, même avec plusieurs workers : la référence de
 * l'évènement est unique par workflow (workflow_runs.trigger_ref).
 *
 * Le contenu d'un évènement vient d'AILLEURS (un expéditeur, un logiciel) :
 * il entre au dossier comme une DONNÉE, jamais comme une consigne — comme
 * toute pièce (WorkflowPrompt).
 */
final class WorkflowTriggers
{
    /** Délai entre deux vérifications d'une boîte ou d'un dossier. */
    public const WATCH_SEC = 300;
    /** Workflows vérifiés par passage du planificateur. */
    public const WATCH_PER_TICK = 10;
    /** Exécutions lancées au plus par vérification (les suivantes attendent la prochaine). */
    public const WATCH_LAUNCH_MAX = 5;
    /** Identifiants retenus comme « déjà vus ». */
    public const SEEN_MAX = 300;
    /** Appels de l'adresse acceptés par heure et par workflow. */
    public const HOOK_PER_HOUR = 60;
    /** Taille maximale du corps d'un appel. */
    public const HOOK_MAX_BYTES = 65536;
    public const HOOK_PREFIX = 'wh_';

    // ==================================================================
    // Surveillances
    // ==================================================================

    /** Le workflow surveille-t-il une boîte ou un dossier ? */
    public static function watches(?array $def): bool
    {
        return in_array($def['start']['event']['kind'] ?? '', ['email', 'file'], true);
    }

    /** Empreinte de ce qui est surveillé : une autre boîte, un autre filtre, un autre dossier → nouvelle ligne de base. */
    public static function watchSignature(array $event): string
    {
        return sha1((string)json_encode([
            $event['kind'] ?? '', $event['source'] ?? '', trim((string)($event['query'] ?? '')), trim((string)($event['folder'] ?? '')),
            WorkflowFilters::isEmpty((array)($event['filters'] ?? [])) ? null : $event['filters'],
        ]));
    }

    /**
     * Passage du planificateur : les workflows à vérifier reçoivent leur
     * prochaine échéance PUIS un job de vérification (un par workflow, jamais
     * deux en même temps : clé de déduplication).
     */
    public static function tickWatchers(\DateTimeImmutable $now, bool $dispatch = true): int
    {
        $n = 0;
        try {
            foreach (Workflow::watchDueIds($now, self::WATCH_PER_TICK) as $id) {
                $db = Database::getInstance();
                $db->beginTransaction();
                try {
                    $wf = Workflow::lockWatchDue($id, $now);
                    if ($wf === null) {
                        $db->rollBack();
                        continue;
                    }
                    Workflow::setWatchNext($id, self::watches($wf['published']) ? $now->modify('+' . self::WATCH_SEC . ' seconds') : null);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('[Workflows] surveillance #' . $id . ' : ' . $e->getMessage());
                    continue;
                }
                if (!self::watches($wf['published'])) {
                    continue;
                }
                $jobId = JobQueue::enqueue('workflow', $wf['user_id'], null, [
                    'op' => 'watch', 'workflow_id' => $id, 'workflow' => ['scheduled' => true],
                ], mb_substr(t('Surveillance') . ' — ' . $wf['name'], 0, 60), 'wf_watch:' . $id);
                $n++;
                if ($dispatch) {
                    JobQueue::dispatch($jobId, false);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] surveillances : ' . $e->getMessage());
        }
        return $n;
    }

    /** Job `watch` : vérifier la boîte ou le dossier, lancer une exécution par nouveauté. */
    public static function runWatchJob(array $payload, ?object $service = null): array
    {
        $wf = Workflow::findById((int)($payload['workflow_id'] ?? 0));
        if ($wf === null || !$wf['is_active'] || !self::watches($wf['published'])) {
            return ['skipped' => true];
        }
        $user = User::findById($wf['user_id']);
        if (!$user || !Workflow::canUse($user)) {
            return ['skipped' => true];
        }
        $event = $wf['published']['start']['event'];
        $tz = self::timezoneOf($wf['published']);
        $state = Workflow::watchState($wf);
        $sig = self::watchSignature($event);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $svc = $service ?? self::serviceFor($event, (int)$user['id']);
            $items = self::listItems($event, $svc);
        } catch (\RuntimeException $e) {
            $cause = $e->getMessage();
            $first = ($state['error'] ?? '') === '';
            $state['error'] = mb_substr($cause, 0, 300);
            $state['checked_at'] = ScheduledTask::sqlUtc($now);
            Workflow::saveWatchState($wf['id'], $state);
            if ($first) {
                WorkflowNotifier::watchBroken($wf, $cause);
            }
            return ['error' => $cause];
        }
        $state['error'] = '';
        $state['checked_at'] = ScheduledTask::sqlUtc($now);

        $seen = array_values(array_map('strval', (array)($state['seen'] ?? [])));
        if (($state['sig'] ?? '') !== $sig) {
            // Première vérification (ou surveillance changée) : ce qui est là
            // ne lance rien.
            $state = ['sig' => $sig, 'seen' => array_slice(array_column($items, 'id'), 0, self::SEEN_MAX), 'baseline_at' => ScheduledTask::sqlUtc($now),
                'checked_at' => $state['checked_at'], 'error' => '', 'launched' => (int)($state['launched'] ?? 0)];
            Workflow::saveWatchState($wf['id'], $state);
            return ['baseline' => count($items)];
        }

        $new = array_values(array_filter($items, static fn(array $i): bool => !in_array((string)$i['id'], $seen, true)));
        // Plus anciens d'abord : les listes arrivent des plus récents aux plus anciens.
        $new = array_reverse($new);
        $launched = 0;
        foreach (array_slice($new, 0, self::WATCH_LAUNCH_MAX) as $item) {
            $ref = $event['kind'] === 'email' ? $event['source'] . ':' . $item['id'] : 'drive:' . $item['id'];
            try {
                $content = self::eventContent($event, $svc, $item, $tz);
                $res = WorkflowEngine::launch($wf, $user, [], [], $event['kind'], null, $tz, $content, $ref);
                $seen[] = (string)$item['id'];
                if ($res !== null) {
                    $launched++;
                    foreach ($res['job_ids'] as $jobId) {
                        JobQueue::dispatch($jobId, false);
                    }
                }
            } catch (\InvalidArgumentException $e) {
                // Quota, budget : on réessaiera à la prochaine vérification.
                error_log('[Workflows] évènement de #' . $wf['id'] . ' non lancé : ' . $e->getMessage());
                break;
            } catch (\RuntimeException $e) {
                // Contenu illisible : l'évènement est écarté, pas rejoué sans fin.
                $seen[] = (string)$item['id'];
                error_log('[Workflows] évènement de #' . $wf['id'] . ' illisible : ' . $e->getMessage());
            }
        }
        $state['seen'] = array_slice(array_values(array_unique($seen)), -self::SEEN_MAX);
        $state['launched'] = (int)($state['launched'] ?? 0) + $launched;
        Workflow::saveWatchState($wf['id'], $state);
        return ['launched' => $launched, 'new' => count($new)];
    }

    /**
     * Fuseau d'une exécution lancée par un évènement : celui de l'évènement
     * (posé par l'éditeur), sinon celui de la programmation — jamais l'UTC du
     * serveur quand la personne en a donné un.
     */
    public static function timezoneOf(array $def): string
    {
        $tz = ScheduledTask::normalizeTimezone((string)($def['start']['event']['timezone'] ?? ''));
        return $tz !== '' ? $tz : ScheduledTask::normalizeTimezone((string)($def['start']['schedule']['timezone'] ?? ''));
    }

    /** Le service du connecteur surveillé, ou une erreur affichable. */
    private static function serviceFor(array $event, int $userId): object
    {
        if ($event['kind'] === 'email') {
            $gmail = $event['source'] !== 'outlook';
            $enabled = $gmail ? UserGmailService::isGloballyEnabled() : UserOutlookService::isGloballyEnabled();
            $svc = $enabled ? ($gmail ? UserGmailService::forUser($userId) : UserOutlookService::forUser($userId)) : null;
            if ($svc === null || !$svc->canRead()) {
                throw new \RuntimeException(t('{service} non connecté — reconnectez-le dans Réglages → Comptes connectés.', ['service' => $gmail ? 'Gmail' : 'Outlook']));
            }
            return $svc;
        }
        $svc = UserDriveService::isGloballyEnabled() ? UserDriveService::forUser($userId) : null;
        if ($svc === null) {
            throw new \RuntimeException(t('{service} non connecté — reconnectez-le dans Réglages → Comptes connectés.', ['service' => 'Google Drive']));
        }
        return $svc;
    }

    /**
     * Ce qui est là maintenant : des emails (récents d'abord) ou les fichiers
     * du dossier. @return list<array{id: string, …}>
     */
    private static function listItems(array $event, object $svc): array
    {
        // Filtres du déclencheur : composés avec la requête (emails), ou
        // appliqués aux fichiers du dossier (nature, nom).
        $scope = SearchScope::fromFilters((array)($event['filters'] ?? []));
        if ($event['kind'] === 'email') {
            if ($event['source'] === 'outlook') {
                return array_values(array_filter($scope->filterOutlook($svc->search($scope->outlookQuery((string)$event['query']), 20)), static fn($m) => (string)($m['id'] ?? '') !== ''));
            }
            return array_values(array_filter($svc->search(self::gmailQuery($scope->gmailQuery((string)$event['query'])), 20), static fn($m) => (string)($m['id'] ?? '') !== ''));
        }
        $list = $svc->listFolder((string)$event['folder'], 100);
        $files = array_values(array_filter((array)($list['elements'] ?? []), static fn($f) => empty($f['dossier']) && (string)($f['id'] ?? '') !== ''));
        return $scope->filterDrive($files);
    }

    /** Requête Gmail d'une surveillance : la boîte de réception sauf mention contraire, les trois derniers jours. */
    public static function gmailQuery(string $query): string
    {
        $q = trim($query);
        if (!preg_match('/\b(in|label):/i', $q)) {
            $q = trim('in:inbox ' . $q);
        }
        if (!preg_match('/\b(newer_than|after|older_than|before):/i', $q)) {
            $q .= ' newer_than:3d';
        }
        return trim($q);
    }

    /** Contenu de la pièce d'évènement d'un email ou d'un fichier. */
    private static function eventContent(array $event, object $svc, array $item, string $tz): array
    {
        if ($event['kind'] === 'email') {
            $msg = $svc->readMessage((string)$item['id'], 6000);
            $from = (string)($msg['de'] ?? $item['de'] ?? '');
            $address = UserGmailService::extractAddress($from);
            $subject = (string)($msg['objet'] ?? $item['objet'] ?? '');
            $service = $event['source'] === 'outlook' ? 'Outlook' : 'Gmail';
            $files = array_values(array_map(static fn($a): string => (string)($a['nom'] ?? ''), (array)($msg['pieces_jointes'] ?? [])));
            return [
                // De quoi relire le message (ses pièces jointes, une boucle qui les parcourt).
                'message_id' => (string)$item['id'],
                'service'    => $event['source'] === 'outlook' ? 'outlook' : 'gmail',
                'fields' => [
                    'expediteur'     => WorkflowValues::email($address),
                    'objet'          => $subject,
                    'recu_le'        => self::dateOf((string)($msg['date'] ?? $item['date'] ?? ''), $tz),
                    'contenu'        => mb_substr((string)($msg['contenu'] ?? ''), 0, 24000),
                    'pieces_jointes' => array_values(array_filter($files, static fn($n) => $n !== '')),
                ],
                'summary'  => t('Email reçu dans {service} de {de} (identifiant du message : {id}). Une étape qui consulte {service} peut le relire, lire ses pièces jointes ou y répondre dans le fil.', [
                    'service' => $service, 'de' => $from !== '' ? $from : '—', 'id' => (string)$item['id'],
                ]),
                'headline' => ($subject !== '' ? '« ' . $subject . ' »' : t('(sans objet)')) . ($from !== '' ? ' — ' . $from : ''),
                'provenance' => [t('Email de {de}', ['de' => $from])],
            ];
        }
        $text = '';
        try {
            $read = $svc->readFile((string)$item['id'], 20000);
            $text = (string)($read['contenu'] ?? '');
        } catch (\RuntimeException $e) {
            $text = t('(contenu illisible : {cause})', ['cause' => $e->getMessage()]);
        }
        return [
            'name'     => (string)($item['nom'] ?? ''),
            'type'     => (string)($item['type'] ?? ''),
            'path'     => '',
            'text'     => $text,
            'url'      => 'https://drive.google.com/file/d/' . rawurlencode((string)$item['id']) . '/view',
            // De quoi relire le fichier lui-même (une boucle qui en parcourt les lignes, les slides…).
            'drive_id' => (string)$item['id'],
            'headline' => '« ' . (string)($item['nom'] ?? '') . ' »',
            'provenance' => [t('Fichier Drive « {nom} »', ['nom' => (string)($item['nom'] ?? '')])],
        ];
    }

    /** Date d'un en-tête d'email (RFC 2822, ou « jj/mm/aaaa hh:mm ») → AAAA-MM-JJ dans le fuseau de la personne. */
    public static function dateOf(string $raw, string $tz): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $raw, $m)) {
            return WorkflowValues::date($m[1] . '/' . $m[2] . '/' . $m[3]);
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(Recurrence::zone($tz))->format('Y-m-d');
    }

    // ==================================================================
    // Adresse d'appel (webhook)
    // ==================================================================

    /** Jeton neuf : préfixe + 32 octets aléatoires en base64url. */
    public static function newHookToken(): string
    {
        return self::HOOK_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hookHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function validHookToken(string $token): bool
    {
        return (bool)preg_match('/^' . self::HOOK_PREFIX . '[A-Za-z0-9_-]{43}$/', $token);
    }

    public static function hookUrl(string $token): string
    {
        return rtrim((string)APP_URL, '/') . '/hooks/workflows/' . $token;
    }

    /** Le jeton du workflow, créé au premier besoin (ou remplacé : `$renew`). */
    public static function hookToken(array $workflow, bool $renew = false): string
    {
        if (!$renew && !empty($workflow['hook_token_enc'])) {
            try {
                $token = Encryption::decrypt((string)$workflow['hook_token_enc']);
                if (self::validHookToken($token)) {
                    return $token;
                }
            } catch (\Throwable) {
                // illisible : un nouveau jeton le remplace
            }
        }
        $token = self::newHookToken();
        Workflow::setHook((int)$workflow['id'], self::hookHash($token), Encryption::encrypt($token));
        return $token;
    }

    /**
     * Valeurs d'un appel → champs du départ (par clé, sinon par nom) et
     * texte de la pièce « Données reçues ».
     *
     * @return array{values: array, text: string}
     */
    public static function hookValues(array $def, array $body): array
    {
        $values = [];
        $byLabel = [];
        foreach ($body as $k => $v) {
            if (is_string($k)) {
                $byLabel[WorkflowSpec::slug($k)] = $v;
            }
        }
        foreach ($def['start']['fields'] as $f) {
            if ($f['type'] === 'file') {
                continue;
            }
            $v = $body[$f['key']] ?? $byLabel[WorkflowSpec::slug($f['label'])] ?? null;
            if (is_scalar($v)) {
                $values[$f['key']] = is_bool($v) ? ($v ? 'oui' : 'non') : (string)$v;
            } elseif (is_array($v) && array_is_list($v)) {
                $values[$f['key']] = implode("\n", array_map(static fn($x) => is_scalar($x) ? (string)$x : '', $v));
            }
        }
        $text = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return ['values' => $values, 'text' => mb_substr($text, 0, 40000)];
    }

    // ==================================================================
    // Enchaînement
    // ==================================================================

    /**
     * Une exécution s'est terminée : les workflows de la même personne qui
     * démarrent « quand celui-ci se termine » sont lancés, avec son livrable.
     * Une chaîne est bornée (CHAIN_MAX) : A → B → A ne tourne pas sans fin.
     */
    public static function afterRunDone(array $run): void
    {
        try {
            $followers = Workflow::followersOf((int)$run['workflow_id'], (int)$run['user_id']);
            if ($followers === []) {
                return;
            }
            $user = User::findById($run['user_id']);
            if (!$user || !Workflow::canUse($user)) {
                return;
            }
            $depth = WorkflowRun::chainDepth($run['parent_run_id'] ?? null) + 1;
            $text = self::deliverableText($run);
            $source = Workflow::findById((int)$run['workflow_id']);
            foreach ($followers as $wf) {
                if ($depth >= WorkflowEngine::CHAIN_MAX) {
                    self::post($run, '⏹ ' . WorkflowEngine::asUser($user, static fn(): string => t('« {nom} » n’est pas lancé : une chaîne compte {n} workflows au plus.', ['nom' => $wf['name'], 'n' => WorkflowEngine::CHAIN_MAX])));
                    continue;
                }
                try {
                    $res = WorkflowEngine::launch($wf, $user, [], [], 'workflow', null, (string)$run['timezone'], [
                        'text' => $text,
                        'headline' => t('Après « {nom} »', ['nom' => (string)($source['name'] ?? '')]),
                        'provenance' => [],
                    ], 'run:' . $run['id'], (int)$run['id']);
                } catch (\InvalidArgumentException $e) {
                    self::post($run, '⚠️ ' . WorkflowEngine::asUser($user, static fn(): string => t('« {nom} » n’a pas pu être lancé : {cause}', ['nom' => $wf['name'], 'cause' => $e->getMessage()])));
                    continue;
                }
                if ($res === null) {
                    continue;
                }
                self::post($run, '▶ ' . WorkflowEngine::asUser($user, static fn(): string => t('Workflow suivant lancé : [« {nom} »]({url}).', ['nom' => $wf['name'], 'url' => '/workflows/runs/' . $res['run_id']])));
                foreach ($res['job_ids'] as $jobId) {
                    JobQueue::dispatch($jobId, false);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] enchaînement après l’exécution #' . (int)($run['id'] ?? 0) . ' : ' . $e->getMessage());
        }
    }

    /** Le livrable d'une exécution, en texte (pièce de départ du workflow suivant). */
    public static function deliverableText(array $run): string
    {
        $def = $run['definition'];
        $pieces = WorkflowSpec::pieces($def);
        $artifacts = WorkflowRun::artifacts($run['id']);
        $out = [];
        $keys = $def['end']['pieces'] !== [] ? $def['end']['pieces'] : array_keys($artifacts);
        foreach ($keys as $k) {
            if (isset($pieces[$k], $artifacts[$k])) {
                $out[] = '## ' . $pieces[$k]['name'] . "\n\n" . trim(ArtifactContract::render($pieces[$k], $artifacts[$k]['content'], 30000));
            }
        }
        return mb_substr(implode("\n\n", $out), 0, 120000);
    }

    // ==================================================================
    // Lancement à la main d'un workflow à évènement (essai)
    // ==================================================================

    /**
     * Contenu d'évènement saisi dans le formulaire de lancement (un essai à
     * la main) : le texte d'un email collé, des données d'appel, le livrable
     * d'un autre workflow — ou le fichier reçu. Null si rien n'est fourni.
     */
    public static function manualEvent(array $def, string $text, ?array $file): ?array
    {
        $kind = $def['start']['event']['kind'] ?? null;
        if ($kind === null) {
            return null;
        }
        $text = trim(mb_substr($text, 0, 40000));
        if ($kind === 'file') {
            return $file !== null ? [
                'name' => $file['name'], 'type' => $file['type'], 'path' => $file['path'], 'text' => (string)($file['text'] ?? ''),
                'headline' => '« ' . $file['name'] . ' »',
            ] : null;
        }
        if ($text === '') {
            return null;
        }
        if ($kind === 'email') {
            return [
                'fields' => ['expediteur' => null, 'objet' => '', 'recu_le' => null, 'contenu' => $text, 'pieces_jointes' => []],
                'summary' => t('Email collé à la main pour un essai.'),
                'headline' => t('Essai à la main'),
            ];
        }
        return ['text' => $text, 'headline' => t('Essai à la main')];
    }

    private static function post(array $run, string $content): void
    {
        $convId = (int)($run['conversation_id'] ?? 0);
        if ($convId > 0) {
            Message::create($convId, 'assistant', $content);
            Conversation::touch($convId);
        }
    }
}
