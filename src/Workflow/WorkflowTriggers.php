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
 * toutes les WATCH_EMAIL_SEC pour une boîte mail (WATCH_PUSH_SEC quand elle
 * prévient MIA elle-même, MailPush), WATCH_SEC pour un dossier, un job
 * `workflow` op `watch` par workflow à vérifier ;
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
    /** Délai entre deux vérifications d'un dossier Drive. */
    public const WATCH_SEC = 300;
    /**
     * Boîte mail sans notification instantanée : chaque minute. Une
     * vérification ne coûte qu'un appel (les identifiants des derniers
     * messages ; les en-têtes des seuls nouveaux).
     */
    public const WATCH_EMAIL_SEC = 60;
    /** Boîte suivie en push (MailPush) : la vérification n'est plus qu'un secours. */
    public const WATCH_PUSH_SEC = 3600;
    /** Messages récents lus par une vérification d'emails (identifiants seulement). */
    public const WATCH_LIST_MAX = 50;
    /** Nouveaux emails examinés au plus par vérification (filtres compris). */
    public const WATCH_EXAMINE_MAX = 25;
    /** Lectures d'un nouvel élément tentées avant de l'écarter. */
    public const READ_ATTEMPTS = 3;
    /** Tolérance sur l'heure de réception face à la ligne de base. */
    public const BASELINE_SLACK_SEC = 120;
    /** Workflows vérifiés par passage du planificateur (toutes les 30 s). */
    public const WATCH_PER_TICK = 30;
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
        $filters = (array)($event['filters'] ?? []);
        // Filtres ajoutés après coup (sauf de, adressé à) : laissés vides, ils
        // ne changent pas l'empreinte — sinon chaque surveillance existante
        // reposerait sa ligne de base au déploiement, et un email arrivé
        // entre-temps ne lancerait rien.
        foreach (['exclude', 'to'] as $k) {
            if (isset($filters['mail']) && is_array($filters['mail']) && trim((string)($filters['mail'][$k] ?? '')) === '') {
                unset($filters['mail'][$k]);
            }
        }
        return sha1((string)json_encode([
            $event['kind'] ?? '', $event['source'] ?? '', trim((string)($event['query'] ?? '')), trim((string)($event['folder'] ?? '')),
            WorkflowFilters::isEmpty((array)($event['filters'] ?? [])) ? null : $filters,
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
                    Workflow::setWatchNext($id, self::watches($wf['published']) ? $now->modify('+' . self::watchInterval($wf) . ' seconds') : null);
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

    /**
     * Délai avant la prochaine vérification : une heure quand la boîte
     * prévient MIA elle-même (push en service), une minute sinon pour une
     * boîte mail, cinq minutes pour un dossier Drive.
     */
    public static function watchInterval(array $wf): int
    {
        $event = $wf['published']['start']['event'] ?? null;
        if (is_array($event) && ($event['kind'] ?? '') === 'email') {
            return MailPush::isLive((int)$wf['user_id'], ($event['source'] ?? '') === 'outlook' ? 'outlook' : 'gmail')
                ? self::WATCH_PUSH_SEC : self::WATCH_EMAIL_SEC;
        }
        return self::WATCH_SEC;
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
        $prevError = (string)($state['error'] ?? '');
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
        // Pré-contrôle (un compte à relier, un outil retiré) : rien ne part,
        // rien n'est marqué vu — les nouveautés attendront que ce soit réglé.
        // La personne est prévenue une fois, la surveillance le dit.
        if ($new !== []) {
            try {
                WorkflowEngine::asUser($user, static fn() => WorkflowEngine::assertReady($wf, $user, true));
            } catch (WorkflowPreflightException $e) {
                $cause = $e->getMessage();
                if ($prevError === '') {
                    WorkflowNotifier::notStarted($wf, $cause);
                }
                $state['error'] = mb_substr($cause, 0, 300);
                Workflow::saveWatchState($wf['id'], $state);
                error_log('[Workflows] évènement de #' . $wf['id'] . ' non lancé (pré-contrôle) : ' . $cause);
                return ['error' => $cause, 'preflight' => true];
            }
        }
        $launched = 0;
        $examined = 0;
        $fails = array_map('intval', (array)($state['fails'] ?? []));
        $baselineAt = ScheduledTask::fromSqlUtc((string)($state['baseline_at'] ?? ''));
        foreach ($new as $item) {
            if ($launched >= self::WATCH_LAUNCH_MAX || $examined >= self::WATCH_EXAMINE_MAX) {
                break; // la suite à la prochaine vérification
            }
            $examined++;
            $ref = $event['kind'] === 'email' ? $event['source'] . ':' . $item['id'] : 'drive:' . $item['id'];
            try {
                if ($event['kind'] === 'email') {
                    $content = self::matchingEmail($event, $svc, $item, $tz, $baselineAt);
                    if ($content === null) {
                        // Pas pour ce workflow (filtres), ou arrivé avant lui : vu, jamais lancé.
                        $seen[] = (string)$item['id'];
                        continue;
                    }
                } else {
                    $content = self::eventContent($event, $svc, $item, $tz);
                }
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
                // Illisible : écarté au troisième échec, pas rejoué sans fin —
                // mais une panne passagère (service injoignable le temps d'une
                // vérification) ne fait pas perdre l'email.
                $id = (string)$item['id'];
                $fails[$id] = (int)($fails[$id] ?? 0) + 1;
                if ($fails[$id] >= self::READ_ATTEMPTS) {
                    $seen[] = $id;
                    unset($fails[$id]);
                }
                error_log('[Workflows] évènement de #' . $wf['id'] . ' illisible (' . ($fails[$id] ?? self::READ_ATTEMPTS) . '/' . self::READ_ATTEMPTS . ') : ' . $e->getMessage());
            }
        }
        $state['fails'] = array_slice(array_diff_key($fails, array_flip($seen)), -50, null, true);
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
     *
     * Emails : les messages récents de la boîte de réception (seule la
     * recherche avancée, facultative, passe par le fournisseur). Les filtres
     * du déclencheur ne sont PAS confiés à la recherche : MIA les applique
     * elle-même au message (matchingEmail), la même règle pour Gmail et
     * Outlook.
     */
    private static function listItems(array $event, object $svc): array
    {
        if ($event['kind'] === 'email') {
            $query = trim((string)$event['query']);
            if (method_exists($svc, 'watchList')) {
                // Sans recherche avancée : la boîte de réception elle-même (Gmail
                // par son libellé, sans le retard de l'index de recherche).
                $list = $svc->watchList($event['source'] === 'outlook' || $query === '' ? $query : self::gmailQuery($query), self::WATCH_LIST_MAX);
            } else {
                $list = $svc->search($event['source'] === 'outlook' ? $query : self::gmailQuery($query), 20);
            }
            return array_values(array_filter($list, static fn($m) => is_array($m) && (string)($m['id'] ?? '') !== ''));
        }
        // Fichiers du dossier : nature et nom du filtre.
        $scope = SearchScope::fromFilters((array)($event['filters'] ?? []));
        $list = $svc->listFolder((string)$event['folder'], 100);
        $files = array_values(array_filter((array)($list['elements'] ?? []), static fn($f) => empty($f['dossier']) && (string)($f['id'] ?? '') !== ''));
        return $scope->filterDrive($files);
    }

    // ==================================================================
    // Essai des filtres (éditeur)
    // ==================================================================

    /** Emails récents jugés par l'essai des filtres. */
    public const FILTER_TEST_MAX = 10;

    /**
     * Les derniers emails de la boîte, jugés par les filtres du déclencheur
     * avec la MÊME règle que la surveillance (WorkflowFilters) — rien n'est
     * lancé, rien n'est gardé. La ligne de base ne compte pas ici : publié,
     * le workflow ne réagira qu'aux emails arrivés ensuite.
     *
     * @return array{items: list<array{de:string, objet:string, received:?int, match:bool, reason:string}>, matched:int}
     */
    public static function testEmailFilters(array $event, object $svc, int $limit = self::FILTER_TEST_MAX): array
    {
        $filters = (array)(($event['filters'] ?? [])['mail'] ?? []);
        $query = trim((string)($event['query'] ?? ''));
        if ($query !== '' && ($event['source'] ?? '') !== 'outlook' && !preg_match('/\b(in|label):/i', $query)) {
            $query = 'in:inbox ' . $query; // toute la boîte de réception, sans la fenêtre de trois jours
        }
        $list = method_exists($svc, 'watchList') ? $svc->watchList($query, $limit) : $svc->search($query !== '' ? $query : 'in:inbox', $limit);
        $items = [];
        $matched = 0;
        foreach (array_slice(array_values(array_filter($list, static fn($m) => is_array($m) && (string)($m['id'] ?? '') !== '')), 0, $limit) as $item) {
            $meta = $item;
            try {
                if (!array_key_exists('received', $meta) && method_exists($svc, 'watchMeta')) {
                    $meta = $svc->watchMeta((string)$item['id']) + $item;
                }
                $why = WorkflowFilters::emailMismatch($filters, $meta, false);
                if ($why === null && WorkflowFilters::needsFullMessage($filters, $meta)) {
                    $why = WorkflowFilters::emailMismatch($filters, self::mergeMessage($svc->readMessage((string)$item['id'], 3000), $meta), true);
                }
                $reason = $why === null ? '' : WorkflowFilters::mismatchLabel($why, $filters);
            } catch (\RuntimeException $e) {
                $why = 'unreadable';
                $reason = t('illisible : {cause}', ['cause' => $e->getMessage()]);
            }
            $items[] = [
                'id' => (string)$item['id'],
                'de' => (string)($meta['de'] ?? ''),
                'objet' => (string)($meta['objet'] ?? ''),
                'received' => isset($meta['received']) ? (int)$meta['received'] : null,
                'match' => $why === null,
                'reason' => $reason,
            ];
            $matched += $why === null ? 1 : 0;
        }
        return ['items' => $items, 'matched' => $matched];
    }

    /**
     * Le message lu en entier, complété par ses en-têtes : une valeur VIDE
     * de la lecture (en-tête absent du format complet) n'efface pas celle
     * des en-têtes. Pure.
     */
    public static function mergeMessage(array $msg, array $meta): array
    {
        foreach ($meta as $k => $v) {
            if (!array_key_exists($k, $msg) || $msg[$k] === '' || $msg[$k] === null) {
                $msg[$k] = $v;
            }
        }
        return $msg;
    }

    /** Le résultat de l'essai, rendu une fois côté serveur (traduit, échappé). Pure. */
    public static function filterTestHtml(array $res, string $service, string $tz): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $items = (array)($res['items'] ?? []);
        if ($items === []) {
            return '<div class="wf-ftest-res"><p class="wf-ftest-sum">' . $e(t('Aucun email dans la boîte de réception {service} (avec la recherche avancée, s’il y en a une).', ['service' => $service])) . '</p></div>';
        }
        $zone = Recurrence::zone(ScheduledTask::normalizeTimezone($tz));
        $rows = '';
        foreach ($items as $it) {
            $from = (string)$it['de'];
            $name = trim((string)preg_replace('/\s*<[^>]*>\s*$/', '', $from), " \t\"'");
            $addr = UserGmailService::extractAddress($from);
            $when = $it['received'] !== null ? Format::shortDateTime((new \DateTimeImmutable('@' . (int)$it['received']))->setTimezone($zone)) : '';
            $rows .= '<li class="' . ($it['match'] ? 'is-match' : 'is-skip') . '">'
                . '<span class="wf-ftest-mark" aria-hidden="true">' . ($it['match'] ? '✓' : '✕') . '</span>'
                . '<span class="wf-ftest-main"><b>' . $e((string)$it['objet'] !== '' ? (string)$it['objet'] : t('(sans objet)')) . '</b>'
                . '<span class="wf-ftest-meta">' . $e($name !== '' ? $name : $addr) . ($name !== '' && $addr !== '' && $addr !== $name ? ' · ' . $e($addr) : '') . ($when !== '' ? ' · ' . $e($when) : '') . '</span>'
                . '<span class="wf-ftest-why">' . $e($it['match'] ? t('lancerait le workflow') : (string)$it['reason']) . '</span></span></li>';
        }
        $n = count($items);
        $m = (int)($res['matched'] ?? 0);
        $sum = $m === 0
            ? t('Aucun des {n} derniers emails ne correspond aux filtres.', ['n' => $n])
            : I18n::plural($m, '{n} des {total} derniers emails correspond aux filtres.', '{n} des {total} derniers emails correspondent aux filtres.', ['total' => $n]);
        return '<div class="wf-ftest-res"><p class="wf-ftest-sum">' . $e($sum) . '</p><ul class="wf-ftest-list">' . $rows . '</ul>'
            . '<p class="wf-ftest-note">' . $e(t('Rien n’est lancé par cet essai. Publié, le workflow ne réagira qu’aux emails arrivés après.')) . '</p></div>';
    }

    // ==================================================================
    // Essai à la main sur un email récent (fenêtre de lancement)
    // ==================================================================

    /** Emails récents proposés dans la fenêtre de lancement. */
    public const RECENT_EMAILS_MAX = 10;

    /**
     * La liste des derniers emails de la boîte, à choisir pour un essai à la
     * main (boutons radio `event_message`), rendue une fois côté serveur
     * (traduite, échappée). Chaque email dit s'il lancerait le workflow —
     * la même règle que la surveillance (testEmailFilters). Rien n'est coché
     * d'office : lancer sur un email que la personne n'a pas choisi
     * préparerait une réponse à un message qu'elle n'a pas désigné. Pure.
     */
    public static function recentEmailsHtml(array $res, string $service, string $tz): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $items = array_values(array_filter((array)($res['items'] ?? []), static fn($it): bool => is_array($it) && (string)($it['id'] ?? '') !== ''));
        if ($items === []) {
            return '<p class="wf-recent-empty">' . $e(t('Aucun email dans la boîte de réception {service}.', ['service' => $service])) . '</p>';
        }
        $zone = Recurrence::zone(ScheduledTask::normalizeTimezone($tz));
        $rows = '';
        foreach ($items as $it) {
            $from = (string)($it['de'] ?? '');
            $name = trim((string)preg_replace('/\s*<[^>]*>\s*$/', '', $from), " \t\"'");
            $addr = UserGmailService::extractAddress($from);
            $who = $name !== '' ? $name : $addr;
            $when = ($it['received'] ?? null) !== null ? Format::shortDateTime((new \DateTimeImmutable('@' . (int)$it['received']))->setTimezone($zone)) : '';
            $match = !empty($it['match']);
            $rows .= '<li><label class="wf-recent-item' . ($match ? '' : ' is-skip') . '">'
                . '<input type="radio" name="event_message" value="' . $e((string)$it['id']) . '">'
                . '<span class="wf-recent-main"><b>' . $e((string)($it['objet'] ?? '') !== '' ? (string)$it['objet'] : t('(sans objet)')) . '</b>'
                . '<span class="wf-recent-meta">' . $e($who) . ($when !== '' ? ' · ' . $e($when) : '') . '</span>'
                . ($match ? '' : '<span class="wf-recent-why">' . $e(t('Ne lancerait pas le workflow : {raison}', ['raison' => (string)($it['reason'] ?? '')])) . '</span>')
                . '</span></label></li>';
        }
        return '<ul class="wf-recent-list" role="radiogroup" aria-label="' . $e(t('Vos derniers emails')) . '">' . $rows . '</ul>';
    }

    /**
     * Pièce « Email reçu » d'un email CHOISI dans la fenêtre de lancement :
     * exactement celle qu'aurait remise la surveillance (expéditeur, objet,
     * date, contenu, pièces jointes, identifiant du message — de quoi
     * répondre dans le fil). Le message est lu avec la connexion de la
     * personne : un identifiant qui n'est pas dans SA boîte n'est pas lu.
     * Les filtres du déclencheur ne s'appliquent pas : c'est un essai, la
     * personne a choisi l'email.
     *
     * @throws \InvalidArgumentException message présentable
     */
    public static function chosenEmailEvent(array $def, int $userId, string $messageId, ?object $service = null): array
    {
        $event = $def['start']['event'] ?? null;
        if (!is_array($event) || ($event['kind'] ?? '') !== 'email') {
            throw new \InvalidArgumentException(t('Requête invalide.'));
        }
        $messageId = trim($messageId);
        if ($messageId === '' || strlen($messageId) > 512 || !preg_match('/^[A-Za-z0-9=_\-+\/.]+$/', $messageId)) {
            throw new \InvalidArgumentException(t('Requête invalide.'));
        }
        $tz = self::timezoneOf($def);
        try {
            $svc = $service ?? self::serviceFor($event, $userId);
            $msg = $svc->readMessage($messageId, 6000);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException(t('L’email choisi n’a pas pu être lu : {cause}', ['cause' => $e->getMessage()]));
        }
        $content = self::emailContent($event, ['id' => $messageId], $msg, $tz);
        $content['summary'] = t('Email choisi dans {service} pour un essai à la main.', ['service' => $event['source'] === 'outlook' ? 'Outlook' : 'Gmail']) . ' ' . $content['summary'];
        return $content;
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

    /**
     * Un nouvel email lance-t-il ce workflow ? Arrivé avant la surveillance
     * (ramené des archives, ligne de base manquée), ou écarté par ses filtres
     * → null. Les en-têtes d'abord (une lecture légère, et seulement pour les
     * messages jamais vus), le message entier ensuite (mots, pièce jointe) —
     * c'est lui qui devient la pièce d'évènement.
     */
    private static function matchingEmail(array $event, object $svc, array $item, string $tz, ?\DateTimeImmutable $baselineAt): ?array
    {
        $filters = (array)(($event['filters'] ?? [])['mail'] ?? []);
        $meta = $item;
        if (!array_key_exists('received', $meta) && method_exists($svc, 'watchMeta')) {
            $meta = $svc->watchMeta((string)$item['id']) + $item;
        }
        if (isset($meta['received']) && $baselineAt !== null
            && (int)$meta['received'] < $baselineAt->getTimestamp() - self::BASELINE_SLACK_SEC) {
            return null;
        }
        if (!WorkflowFilters::emailMatches($filters, $meta, false)) {
            return null;
        }
        $msg = $svc->readMessage((string)$item['id'], 6000);
        if (!WorkflowFilters::emailMatches($filters, self::mergeMessage($msg, $meta), true)) {
            return null;
        }
        return self::emailContent($event, $item, $msg, $tz);
    }

    /** Contenu de la pièce d'évènement d'un email ou d'un fichier. */
    private static function eventContent(array $event, object $svc, array $item, string $tz): array
    {
        if ($event['kind'] === 'email') {
            return self::emailContent($event, $item, $svc->readMessage((string)$item['id'], 6000), $tz);
        }
        return self::fileContent($svc, $item);
    }

    /** Pièce d'évènement d'un email (déjà lu). */
    private static function emailContent(array $event, array $item, array $msg, string $tz): array
    {
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
            'summary'  => t('Email reçu dans {service} de {de} (identifiant du message : {id}). Ses pièces jointes sont jointes à chaque étape qui le lit ; une étape qui consulte {service} peut aussi le relire en entier ou y répondre dans le fil.', [
                'service' => $service, 'de' => $from !== '' ? $from : '—', 'id' => (string)$item['id'],
            ]),
            'headline' => ($subject !== '' ? '« ' . $subject . ' »' : t('(sans objet)')) . ($from !== '' ? ' — ' . $from : ''),
            'provenance' => [t('Email de {de}', ['de' => $from])],
        ];
    }

    /** Pièce d'évènement d'un fichier Drive. */
    private static function fileContent(object $svc, array $item): array
    {
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
                    WorkflowEngine::asUser($user, static fn() => WorkflowEngine::assertReady($wf, $user));
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
