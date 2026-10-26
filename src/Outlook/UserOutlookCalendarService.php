<?php

declare(strict_types=1);

/**
 * Accès au CALENDRIER OUTLOOK personnel d'un utilisateur (OAuth Microsoft par
 * utilisateur, jetons UserDriveToken provider 'outlook_calendar') : liste des
 * agendas, recherche et lecture d'évènements, création d'évènements — cette
 * dernière UNIQUEMENT via la file d'approbation. Aucune modification, aucune
 * suppression : ces outils n'existent pas (cf. MicrosoftCalendarOAuth pour le
 * raisonnement sur `Calendars.ReadWrite`).
 *
 * Plomberie commune (refresh lazy, jetons roulants, retry 401, erreurs FR sans
 * fuite) : MicrosoftUserApiService — ce fichier ne porte que le métier Agenda.
 *
 * ── QUATRE PIÈGES GRAPH, TOUS PAYÉS DANS LE CODE ───────────────────────────
 *
 *  1. **`calendarView` interprète en UTC un horodatage sans décalage.** Envoyer
 *     `2026-08-17T00:00:00` depuis un serveur en Europe/Paris décale la fenêtre
 *     d'une à deux heures : l'évènement de 01h00 « aujourd'hui » tombe hors
 *     fenêtre, et celui de 01h00 du lendemain y entre. `viewUrl()` envoie donc
 *     toujours un RFC3339 AVEC décalage.
 *
 *  2. **La réponse sépare l'heure du fuseau** : `start.dateTime` n'a AUCUN
 *     décalage et `start.timeZone` vaut « UTC » par défaut. Parser le seul
 *     `dateTime` (comme on le fait légitimement chez Google, qui rend un
 *     RFC3339 complet) affiche donc toutes les heures fausses, sans une erreur.
 *     D'où `formatEventBoundary()`, qui lit le COUPLE.
 *
 *  3. **`calendarView` n'accepte ni `$search` ni `contains(subject,…)`**
 *     (fonction non supportée sur les évènements → HTTP 400). Le filtre texte
 *     est donc appliqué en PHP sur ce que la fenêtre a rendu, et le nombre de
 *     lignes demandées à Graph est élargi en conséquence.
 *
 *  4. **Aucun équivalent de `sendUpdates=none` à la création.** Chez Graph, un
 *     évènement créé AVEC des participants déclenche l'envoi des invitations,
 *     point. MIA n'envoie jamais rien au nom de l'utilisateur : le paramètre
 *     « participants » n'existe pas, et une demande d'en ajouter est REFUSÉE
 *     avec l'explication — plutôt que silencieusement ignorée, ce qui laisserait
 *     croire des invités conviés.
 */
class UserOutlookCalendarService extends MicrosoftUserApiService
{
    private const ME = self::GRAPH_BASE . '/me';

    /** Scopes Graph, en forme courte : `scopeGranted` gère le préfixe d'URI. */
    public const SCOPE_READ      = 'Calendars.Read';
    public const SCOPE_READWRITE = 'Calendars.ReadWrite';

    /** Clé Settings : interrupteur global admin du calendrier Outlook dans le chat. */
    public const SETTING_ENABLED = 'user_mscalendar_enabled';

    /** Fenêtre par défaut d'une recherche sans dates : d'aujourd'hui à +90 jours. */
    private const DEFAULT_RANGE_DAYS = 90;

    /**
     * Filtre texte appliqué en PHP (piège 3) : on demande à Graph davantage de
     * lignes que le plafond voulu, sinon un mot-clé rare ne remonterait qu'au
     * hasard des premiers évènements de la fenêtre.
     */
    private const FILTER_FETCH_FACTOR = 5;
    private const MAX_FETCH           = 100;

    public const MSG_RECONNECT = 'Connexion au calendrier Outlook expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter son calendrier dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_WRITE = 'Autorisation de création d\'évènements non accordée. '
        . 'L\'utilisateur doit reconnecter son calendrier Outlook dans Réglages → Comptes connectés.';

    protected const OAUTH_CLASS       = MicrosoftCalendarOAuth::class;
    protected const TOKEN_PROVIDER    = UserDriveToken::PROVIDER_MSCALENDAR;
    protected const API_LABEL         = 'Calendrier Outlook';
    protected const LOG_LABEL         = '[Agenda Outlook user]';
    protected const MSG_NOT_FOUND     = 'Agenda ou évènement introuvable dans votre calendrier Outlook '
        . '(supprimé ou identifiant invalide).';
    protected const MSG_BAD_REQUEST   = 'Requête refusée par le calendrier Outlook (HTTP 400) — vérifiez les '
        . 'dates et les identifiants fournis.';
    protected const MSG_ACCESS_DENIED = 'Accès refusé par le calendrier Outlook';
    protected const MSG_SCOPE_INSUFFICIENT = 'Permission de calendrier non accordée. L\'utilisateur doit '
        . 'reconnecter son calendrier Outlook dans Réglages → Comptes connectés ; si le refus persiste, un '
        . 'administrateur de l\'annuaire Microsoft doit consentir à la permission Calendars.ReadWrite '
        . 'pour l\'application.';
    protected const MSG_NO_MAILBOX    = 'Ce compte Microsoft n\'a pas de boîte Exchange Online '
        . '(licence absente, ou compte d\'annuaire sans messagerie), donc aucun calendrier exploitable. '
        . 'Aucune reconnexion n\'y changera rien : il faut un compte disposant réellement d\'Outlook.';

    private const DAYS_FR = [
        1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi',
        5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
    ];

    /** Instance pour l'utilisateur, ou null si le calendrier n'est pas connecté (ou révoqué). */
    public static function forUser(int $userId): ?self
    {
        $row = self::tokenRowForUser($userId);
        if ($row === null) {
            return null;
        }
        return new self(
            $userId,
            (string)$row['refresh_token'],
            $row['access_token'] !== null ? (string)$row['access_token'] : null,
            $row['access_token_expires_at'] !== null ? (string)$row['access_token_expires_at'] : null,
            (string)($row['scope'] ?? ''),
            (string)($row['google_email'] ?? '')
        );
    }

    /** Consultation possible ? (l'écriture l'implique) */
    public function canRead(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_READ)
            || self::scopeGranted($this->grantedScope, self::SCOPE_READWRITE);
    }

    /** Scope de création d'évènements accordé lors du consentement ? */
    public function canWrite(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_READWRITE);
    }

    /**
     * Fuseau de l'utilisateur pour CE tour (celui du navigateur) ; '' = celui
     * du serveur. Le piège 2 convertissait chaque horaire vers le fuseau du
     * SERVEUR, qui tourne en UTC : le modèle lisait « 07:00 » pour une réunion
     * de 9 h à Paris, pendant que la date du jour lui était donnée à l'heure
     * de Paris. Recherche, lecture et créneaux libres suivent ce fuseau.
     */
    private string $timeZone = '';

    public function setTimeZone(string $timeZone): void
    {
        $this->timeZone = UserCalendarService::validTimeZone($timeZone);
    }

    /** Fuseau effectif : celui de l'utilisateur, sinon celui du serveur. */
    public function timeZoneName(): string
    {
        return $this->timeZone !== '' ? $this->timeZone : date_default_timezone_get();
    }

    /** Pages de `calendarView` lues au plus pour les créneaux libres (100 évènements chacune). */
    private const BUSY_MAX_PAGES = 5;

    /**
     * Occupations du calendrier du compte — et des participants nommés, quand
     * l'annuaire les rend visibles — sur une fenêtre.
     *
     * Deux sources, chacune pour ce qu'elle sait faire :
     *   - le compte lui-même : `calendarView` (fiable quelle que soit l'adresse
     *     du compte, et `showAs` dit si un évènement occupe réellement : un
     *     évènement « disponible » ou « travaille ailleurs » ne bloque rien) ;
     *   - les participants : `getSchedule`, qui ne rend que des plages et leur
     *     statut — jamais le titre ni le contenu des évènements d'un collègue.
     *
     * @param list<string> $participants adresses email
     * @return array{busy: list<array{0:\DateTimeImmutable,1:\DateTimeImmutable}>, visibles: list<string>, non_visibles: array<string,string>}
     */
    public function busyIntervals(\DateTimeImmutable $from, \DateTimeImmutable $to, array $participants = []): array
    {
        $busy = [];
        $url  = self::viewUrl('', $from, $to, self::MAX_FETCH, 'start,end,showAs,isCancelled,isAllDay');
        for ($page = 0; $page < self::BUSY_MAX_PAGES && $url !== ''; $page++) {
            $data = $this->apiJson('GET', $url);
            foreach ((array)($data['value'] ?? []) as $ev) {
                if (is_array($ev) && ($slot = self::busySlot($ev)) !== null) {
                    $busy[] = $slot;
                }
            }
            // Lien de page suivante : suivi SEULEMENT s'il vise Graph (le jeton
            // partirait avec la requête vers n'importe quelle adresse rendue).
            $next = (string)($data['@odata.nextLink'] ?? '');
            $url  = str_starts_with($next, self::GRAPH_BASE . '/') ? $next : '';
        }

        $participants = array_values(array_filter(
            $participants,
            fn(string $p): bool => $this->accountEmail === '' || strcasecmp($p, $this->accountEmail) !== 0
        ));
        $visibles    = [];
        $nonVisibles = [];
        if ($participants !== []) {
            $utc  = new \DateTimeZone('UTC');
            $data = $this->apiJson('POST', self::ME . '/calendar/getSchedule', [
                'schedules' => $participants,
                'startTime' => ['dateTime' => $from->setTimezone($utc)->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
                'endTime'   => ['dateTime' => $to->setTimezone($utc)->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
                'availabilityViewInterval' => 30,
            ]);
            $parsed      = self::parseSchedule($data, $participants);
            $busy        = array_merge($busy, $parsed['busy']);
            $visibles    = $parsed['visibles'];
            $nonVisibles = $parsed['non_visibles'];
        }
        return ['busy' => $busy, 'visibles' => $visibles, 'non_visibles' => $nonVisibles];
    }

    /**
     * Agendas du compte (id, nom, principal, modifiable).
     * Throw RuntimeException à message FR.
     */
    public function listCalendars(): array
    {
        $data = $this->apiJson(
            'GET',
            self::ME . '/calendars?$top=50&$select=' . rawurlencode('id,name,isDefaultCalendar,canEdit')
        );

        $out = [];
        foreach (($data['value'] ?? []) as $c) {
            if (!is_array($c) || (string)($c['id'] ?? '') === '') {
                continue;
            }
            $out[] = [
                'id'          => (string)$c['id'],
                'nom'         => trim((string)($c['name'] ?? '')) ?: '(sans nom)',
                'principal'   => !empty($c['isDefaultCalendar']),
                'modifiable'  => !empty($c['canEdit']),
            ];
        }
        return $out;
    }

    /**
     * Recherche d'évènements sur une fenêtre de dates (occurrences des séries
     * développées par `calendarView`, ordre chronologique), avec filtre texte
     * optionnel appliqué en PHP sur titre, lieu, aperçu, organisateur et
     * participants. Dates absentes → d'aujourd'hui à +DEFAULT_RANGE_DAYS jours.
     *
     * Retour : liste `{id, agenda, titre, debut, fin, journee_entiere, lieu, annule}`.
     * Throw RuntimeException à message FR.
     */
    public function searchEvents(string $calendarId, string $query, string $from, string $to, int $limit = 10): array
    {
        $calendarId = self::normalizeCalendarId($calendarId);
        $limit      = max(1, min(25, $limit));
        $query      = trim($query);

        $tz     = new \DateTimeZone($this->timeZoneName());
        $fromDt = UserCalendarService::parseDateInput($from, false, $this->timeZone) ?? new \DateTimeImmutable('today', $tz);
        $toDt   = UserCalendarService::parseDateInput($to, true, $this->timeZone)
            ?? $fromDt->modify('+' . self::DEFAULT_RANGE_DAYS . ' days');
        if ($toDt <= $fromDt) {
            throw new \RuntimeException('La date de fin doit être postérieure à la date de début.');
        }

        // Filtre texte en PHP (piège 3) : élargir la collecte, sinon un mot-clé
        // rare ne serait trouvé que s'il tombe dans les premières lignes.
        $fetch = $query === '' ? $limit : min(self::MAX_FETCH, $limit * self::FILTER_FETCH_FACTOR);
        $data  = $this->apiJson('GET', self::viewUrl($calendarId, $fromDt, $toDt, $fetch));

        $out = [];
        foreach (($data['value'] ?? []) as $ev) {
            if (!is_array($ev) || (string)($ev['id'] ?? '') === '') {
                continue;
            }
            if ($query !== '' && !self::matchesQuery($ev, $query)) {
                continue;
            }
            $out[] = self::summarizeEvent($ev, $calendarId, $this->timeZone);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Lit un évènement complet : titre, bornes, lieu, description (tronquée au
     * budget), participants et lien Outlook.
     *
     * L'évènement est lu sur `/me/events/{id}` et non sur son agenda : dans une
     * boîte Outlook, l'identifiant d'un élément suffit à le retrouver quel que
     * soit l'agenda qui le porte — passer un id d'agenda qui ne correspond pas
     * (série développée, agenda secondaire) rendrait un 404 inexplicable.
     *
     * Throw RuntimeException à message FR.
     */
    public function readEvent(string $eventId, int $tokenBudget): array
    {
        $id = self::assertEventId($eventId);
        $ev = $this->apiJson(
            'GET',
            self::ME . '/events/' . rawurlencode($id) . '?$select=' . rawurlencode(
                'id,subject,bodyPreview,body,location,start,end,isAllDay,isCancelled,'
                . 'organizer,attendees,webLink,onlineMeetingUrl'
            )
        );

        $desc = self::plainText((string)($ev['body']['content'] ?? ''), (string)($ev['body']['contentType'] ?? ''));
        if ($desc === '') {
            $desc = trim((string)($ev['bodyPreview'] ?? ''));
        }
        $maxChars  = max(1000, $tokenBudget * 4);
        $truncated = mb_strlen($desc) > $maxChars;
        if ($truncated) {
            $desc = mb_substr($desc, 0, $maxChars) . "\n[… contenu tronqué]";
        }

        $attendees = [];
        foreach (array_slice((array)($ev['attendees'] ?? []), 0, 20) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $ligne = UserOutlookService::formatRecipient($a);
            if ($ligne === '') {
                continue;
            }
            $reponse = strtolower((string)($a['status']['response'] ?? ''));
            $attendees[] = $ligne
                . ($reponse === 'declined' ? ' (décliné)' : '')
                . ($reponse === 'accepted' ? ' (accepté)' : '');
        }

        $allDay = !empty($ev['isAllDay']);

        return [
            'titre'           => trim((string)($ev['subject'] ?? '')) ?: '(sans titre)',
            'debut'           => self::formatEventBoundary($ev['start'] ?? null, $allDay, $this->timeZone),
            'fin'             => self::formatEventEnd($ev['end'] ?? null, $allDay, $this->timeZone),
            'journee_entiere' => $allDay,
            'annule'          => !empty($ev['isCancelled']),
            'lieu'            => trim((string)($ev['location']['displayName'] ?? '')),
            'organisateur'    => UserOutlookService::formatRecipient($ev['organizer'] ?? []),
            'description'     => $desc,
            'participants'    => $attendees,
            'visio'           => trim((string)($ev['onlineMeetingUrl'] ?? '')),
            'lien'            => (string)($ev['webLink'] ?? ''),
            'tronque'         => $truncated,
        ];
    }

    /**
     * Crée un évènement (appelé uniquement depuis la file d'approbation, au clic
     * « Approuver »). AUCUN participant n'est accepté : Graph n'offre pas de
     * création silencieuse, ajouter un invité l'inviterait (piège 4).
     *
     * @return array{id:string,titre:string,quand:string,url:string,agenda:string}
     */
    public function createEvent(array $args): array
    {
        if (!$this->canWrite()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $calendarId = self::normalizeCalendarId((string)($args['agenda'] ?? ''));
        $payload    = self::buildEventPayload($args);

        $ev = $this->apiJson('POST', self::calendarPath($calendarId) . '/events', $payload);
        if ((string)($ev['id'] ?? '') === '') {
            throw new \RuntimeException('Création de l\'évènement refusée par le calendrier Outlook.');
        }
        // Trace de succès (id tronqué seul, jamais de contenu) — diagnostic staging.
        error_log('[Agenda Outlook user] évènement créé id=' . mb_substr((string)$ev['id'], 0, 24) . '…'
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return [
            'id'     => (string)$ev['id'],
            'titre'  => trim((string)($ev['subject'] ?? ($args['titre'] ?? ''))) ?: '(sans titre)',
            'quand'  => self::formatEventBoundary($ev['start'] ?? null, !empty($ev['isAllDay']), UserCalendarService::argTimeZone($args)),
            // webLink vient de Graph : le domaine diffère selon le type de compte
            // (outlook.office.com / outlook.live.com), le déduire enverrait la
            // moitié des utilisateurs sur une page vide.
            'url'    => (string)($ev['webLink'] ?? '') !== ''
                ? (string)$ev['webLink']
                : 'https://outlook.office.com/calendar/view/month',
            'agenda' => $calendarId,
        ];
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /**
     * Id d'agenda nettoyé. Chaîne vide (ou « principal ») = agenda PAR DÉFAUT :
     * Graph n'a pas d'id littéral pour lui, c'est le chemin `/me/calendar` qui
     * le désigne — d'où '' plutôt qu'un « primary » à la Google, qui partirait
     * en segment d'URL et rendrait un 404.
     */
    public static function normalizeCalendarId(string $id): string
    {
        $id = trim($id);
        foreach (['', 'primary', 'principal', 'defaut', 'défaut', 'default'] as $defaut) {
            if (strcasecmp($id, $defaut) === 0) {
                return '';
            }
        }
        if (preg_match('#^[A-Za-z0-9_\-=]{16,1024}$#', $id) !== 1) {
            throw new \RuntimeException(
                'Identifiant d\'agenda Outlook invalide — utilise lister_agendas_outlook d\'abord.'
            );
        }
        return $id;
    }

    /** Racine Graph d'un agenda : le défaut est un chemin, pas un id (cf. normalizeCalendarId). */
    public static function calendarPath(string $calendarId): string
    {
        return $calendarId === ''
            ? self::ME . '/calendar'
            : self::ME . '/calendars/' . rawurlencode($calendarId);
    }

    /**
     * Identifiant d'évènement Graph valide. Renvoie l'identifiant nettoyé, throw
     * sinon : sans ce garde, un id inventé par le modèle partirait en requête et
     * rendrait un 400 illisible plutôt qu'une consigne claire. Même jeu de
     * caractères que les identifiants de message (base64 URL-safe) — ni `.` ni
     * `/`, qui n'y apparaissent pas et laisseraient passer un `../..`.
     */
    public static function assertEventId(string $id): string
    {
        $id = trim($id);
        if (mb_strlen($id) < 16 || mb_strlen($id) > 1024
            || preg_match('#^[A-Za-z0-9_\-=]+$#', $id) !== 1) {
            throw new \RuntimeException(
                'Identifiant d\'évènement Outlook invalide — utilise rechercher_agenda_outlook d\'abord.'
            );
        }
        return $id;
    }

    /**
     * URL `calendarView` d'une fenêtre de dates. Les bornes portent TOUJOURS
     * leur décalage horaire (RFC3339) : sans lui Graph les lit en UTC et la
     * fenêtre glisse d'une à deux heures (piège 1). `$orderby=start/dateTime`
     * est accepté par `calendarView` — contrairement à `$search`, qui n'y existe
     * pas du tout et n'est donc jamais tenté.
     */
    public static function viewUrl(
        string $calendarId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $top,
        string $select = 'id,subject,bodyPreview,location,start,end,isAllDay,isCancelled,organizer,attendees,webLink'
    ): string {
        $top = max(1, min(self::MAX_FETCH, $top));

        return self::calendarPath($calendarId) . '/calendarView?'
            . 'startDateTime=' . rawurlencode($from->format(DATE_RFC3339))
            . '&endDateTime=' . rawurlencode($to->format(DATE_RFC3339))
            . '&$top=' . $top
            . '&$orderby=' . rawurlencode('start/dateTime')
            . '&$select=' . rawurlencode($select);
    }

    /**
     * Plage OCCUPÉE d'un évènement du compte, ou null s'il ne bloque rien :
     * annulé, ou marqué « disponible » / « travaille ailleurs » (`showAs`).
     * « Provisoire » et « absent » bloquent — proposer un créneau sur une
     * réunion provisoire, c'est créer le conflit qu'on cherchait à éviter.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}|null
     */
    public static function busySlot(array $ev): ?array
    {
        if (!empty($ev['isCancelled'])) {
            return null;
        }
        $showAs = strtolower((string)($ev['showAs'] ?? 'busy'));
        if (in_array($showAs, ['free', 'workingelsewhere'], true)) {
            return null;
        }
        $s = self::parseBoundary($ev['start'] ?? null);
        $e = self::parseBoundary($ev['end'] ?? null);
        return ($s !== null && $e !== null && $e > $s) ? [$s, $e] : null;
    }

    /**
     * Plages rendues par `getSchedule` pour les participants : « occupé »,
     * « provisoire » et « absent » bloquent ; une adresse en erreur (hors
     * annuaire, agenda non partagé) est nommée, jamais comptée comme libre.
     *
     * @param list<string> $participants
     * @return array{busy: list<array{0:\DateTimeImmutable,1:\DateTimeImmutable}>, visibles: list<string>, non_visibles: array<string,string>}
     */
    public static function parseSchedule(array $data, array $participants): array
    {
        $byLower = [];
        foreach ($participants as $p) {
            $byLower[strtolower($p)] = $p;
        }
        $busy = [];
        $visibles = [];
        $nonVisibles = [];
        foreach ((array)($data['value'] ?? []) as $sched) {
            if (!is_array($sched)) {
                continue;
            }
            $who = $byLower[strtolower((string)($sched['scheduleId'] ?? ''))] ?? (string)($sched['scheduleId'] ?? '');
            if (!empty($sched['error'])) {
                $nonVisibles[$who] = 'agenda non consultable (hors annuaire ou non partagé)';
                continue;
            }
            $visibles[] = $who;
            foreach ((array)($sched['scheduleItems'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $status = strtolower((string)($item['status'] ?? 'busy'));
                if (!in_array($status, ['busy', 'tentative', 'oof'], true)) {
                    continue;
                }
                $s = self::parseBoundary($item['start'] ?? null);
                $e = self::parseBoundary($item['end'] ?? null);
                if ($s !== null && $e !== null && $e > $s) {
                    $busy[] = [$s, $e];
                }
            }
        }
        foreach ($participants as $p) {
            if (!in_array($p, $visibles, true) && !isset($nonVisibles[$p])) {
                $nonVisibles[$p] = 'agenda non communiqué par Microsoft';
            }
        }
        return ['busy' => $busy, 'visibles' => $visibles, 'non_visibles' => $nonVisibles];
    }

    /**
     * L'évènement correspond-il au filtre texte ? Comparaison insensible à la
     * casse ET aux accents (« reunion » trouve « réunion »), sur le titre, le
     * lieu, l'aperçu, l'organisateur et les participants — le périmètre que
     * couvre le `q` de Google, pour que les deux connecteurs se comportent
     * pareil vu du modèle.
     */
    public static function matchesQuery(array $ev, string $query): bool
    {
        $besoin = self::foldText($query);
        if ($besoin === '') {
            return true;
        }
        $foin = [
            (string)($ev['subject'] ?? ''),
            (string)($ev['bodyPreview'] ?? ''),
            (string)($ev['location']['displayName'] ?? ''),
            UserOutlookService::formatRecipient($ev['organizer'] ?? []),
        ];
        foreach ((array)($ev['attendees'] ?? []) as $a) {
            if (is_array($a)) {
                $foin[] = UserOutlookService::formatRecipient($a);
            }
        }
        return str_contains(self::foldText(implode(' ', $foin)), $besoin);
    }

    /**
     * Arguments de l'outil creer_evenement_agenda_outlook → corps de l'API
     * events.create.
     *
     * Journée entière (`journee_entiere=true` OU dates sans heure) → `isAllDay`
     * avec bornes à MINUIT et fin EXCLUSIVE : Graph refuse tout autre horaire
     * sur un évènement de journée entière. Sinon start/end `dateTime` +
     * `timeZone` du serveur (fin absente → début + 1 h).
     *
     * Une demande de participants est REFUSÉE : cf. piège 4 en tête de fichier.
     * Throw RuntimeException à message FR sur toute entrée invalide.
     */
    public static function buildEventPayload(array $args): array
    {
        if (trim((string)($args['invites'] ?? '')) !== '' || !empty($args['participants'])) {
            throw new \RuntimeException(
                'Impossible d\'ajouter des participants : Microsoft envoie l\'invitation dès la création de '
                . 'l\'évènement, et MIA n\'envoie jamais de message au nom de l\'utilisateur. Crée '
                . 'l\'évènement sans participant, et invite-le à les ajouter lui-même dans Outlook.'
            );
        }

        $titre = trim((string)($args['titre'] ?? ''));
        if ($titre === '') {
            throw new \RuntimeException('Titre de l\'évènement manquant.');
        }
        $debutRaw = trim((string)($args['debut'] ?? ''));
        if ($debutRaw === '') {
            throw new \RuntimeException('Date de début manquante (format AAAA-MM-JJ ou AAAA-MM-JJ HH:MM).');
        }
        $finRaw  = trim((string)($args['fin'] ?? ''));
        $dayOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $debutRaw) === 1;
        $allDay  = !empty($args['journee_entiere'])
            || ($dayOnly && ($finRaw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $finRaw) === 1));

        // Heures lues dans le fuseau de l'UTILISATEUR (cf. UserCalendarService::argTimeZone).
        $tzName  = UserCalendarService::argTimeZone($args);
        $payload = ['subject' => mb_substr($titre, 0, 255)];

        if ($allDay) {
            if (!$dayOnly) {
                throw new \RuntimeException('Un évènement « journée entière » attend des dates sans heure (AAAA-MM-JJ).');
            }
            $start = UserCalendarService::parseDateInput($debutRaw, false, $tzName);
            $end   = $finRaw !== '' ? UserCalendarService::parseDateInput($finRaw, false, $tzName) : $start;
            if ($start === null || $end === null || $end < $start) {
                throw new \RuntimeException('La date de fin doit être postérieure ou égale à la date de début.');
            }
            // Fin exclusive, bornes à minuit : les deux sont exigées par Graph.
            $payload['isAllDay'] = true;
            $payload['start']    = ['dateTime' => $start->format('Y-m-d') . 'T00:00:00', 'timeZone' => $tzName];
            $payload['end']      = ['dateTime' => $end->modify('+1 day')->format('Y-m-d') . 'T00:00:00', 'timeZone' => $tzName];
        } else {
            $start = UserCalendarService::parseDateInput($debutRaw, false, $tzName);
            if ($start === null || $dayOnly) {
                throw new \RuntimeException('Heure de début manquante (format AAAA-MM-JJ HH:MM), ou passe journee_entiere=true.');
            }
            $end = $finRaw !== '' ? UserCalendarService::parseDateInput($finRaw, false, $tzName) : $start->modify('+1 hour');
            if ($end === null || $end <= $start) {
                throw new \RuntimeException('L\'heure de fin doit être postérieure à l\'heure de début.');
            }
            // Heure LOCALE + fuseau nommé : un RFC3339 dans `dateTime` serait
            // refusé, Graph attend une heure « nue » accompagnée du fuseau.
            $payload['start'] = ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $tzName];
            $payload['end']   = ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => $tzName];
        }

        $desc = trim((string)($args['description'] ?? ''));
        if ($desc !== '') {
            // Texte brut : jamais de HTML fabriqué depuis le texte du modèle.
            $payload['body'] = ['contentType' => 'Text', 'content' => mb_substr($desc, 0, 8000)];
        }
        $lieu = trim((string)($args['lieu'] ?? ''));
        if ($lieu !== '') {
            $payload['location'] = ['displayName' => mb_substr($lieu, 0, 1000)];
        }
        return $payload;
    }

    /** Ligne de résultat de recherche (mêmes clés FR que l'agenda Google). */
    public static function summarizeEvent(array $ev, string $calendarId, string $timeZone = ''): array
    {
        $allDay = !empty($ev['isAllDay']);

        return [
            'id'              => (string)($ev['id'] ?? ''),
            'agenda'          => $calendarId,
            'titre'           => trim((string)($ev['subject'] ?? '')) ?: '(sans titre)',
            'debut'           => self::formatEventBoundary($ev['start'] ?? null, $allDay, $timeZone),
            'fin'             => self::formatEventEnd($ev['end'] ?? null, $allDay, $timeZone),
            'journee_entiere' => $allDay,
            'annule'          => !empty($ev['isCancelled']),
            'lieu'            => trim((string)($ev['location']['displayName'] ?? '')),
        ];
    }

    /**
     * Borne d'un évènement Graph → texte FR lisible dans le fuseau du serveur :
     * « vendredi 07/08/2026 09:00 », ou « vendredi 07/08/2026 (journée
     * entière) » quand l'évènement en est un (l'heure y est toujours minuit,
     * l'afficher ne dirait rien).
     */
    public static function formatEventBoundary(mixed $node, bool $allDay = false, string $timeZone = ''): string
    {
        $local = self::parseBoundary($node, $timeZone);
        if ($local === null) {
            return is_array($node) ? (string)($node['dateTime'] ?? '') : '';
        }
        return self::formatLocal($local, $allDay);
    }

    /**
     * Fin d'un évènement. Sur une journée entière, Graph rend une fin
     * EXCLUSIVE (minuit du lendemain) : l'afficher telle quelle annoncerait un
     * jour de trop — « du 17 au 19 » pour un évènement des 17 et 18. On rend
     * donc le dernier jour réellement occupé.
     */
    public static function formatEventEnd(mixed $node, bool $allDay = false, string $timeZone = ''): string
    {
        $local = self::parseBoundary($node, $timeZone);
        if ($local === null) {
            return is_array($node) ? (string)($node['dateTime'] ?? '') : '';
        }
        return self::formatLocal($allDay ? $local->modify('-1 day') : $local, $allDay);
    }

    /**
     * Couple `{dateTime, timeZone}` de Graph → instant dans le fuseau demandé
     * (celui de l'utilisateur), à défaut celui du serveur ; null si illisible.
     *
     * Le couple est lu ENTIER (piège 2) : `dateTime` n'a AUCUN décalage et
     * `timeZone` vaut « UTC » par défaut, donc parser le seul horodatage
     * afficherait des heures fausses sans une erreur.
     */
    public static function parseBoundary(mixed $node, string $timeZone = ''): ?\DateTimeImmutable
    {
        if (!is_array($node) || trim((string)($node['dateTime'] ?? '')) === '') {
            return null;
        }
        $brut = trim((string)$node['dateTime']);
        try {
            $source = new \DateTimeZone(self::graphTimeZone((string)($node['timeZone'] ?? '')));
            // Graph rend 7 décimales de seconde : DateTimeImmutable les accepte,
            // mais tronquer garde l'analyse insensible à une variation de format.
            $cible = UserCalendarService::validTimeZone($timeZone);
            return (new \DateTimeImmutable((string)preg_replace('/\.\d+$/', '', $brut), $source))
                ->setTimezone(new \DateTimeZone($cible !== '' ? $cible : date_default_timezone_get()));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Instant local → « vendredi 07/08/2026 09:00 » (ou la journée seule). */
    private static function formatLocal(\DateTimeImmutable $local, bool $allDay): string
    {
        $jour = self::DAYS_FR[(int)$local->format('N')] . ' ' . $local->format('d/m/Y');

        return $allDay ? $jour . ' (journée entière)' : $jour . ' ' . $local->format('H:i');
    }

    /**
     * Nom de fuseau Graph → nom IANA utilisable par PHP. Graph renvoie « UTC »
     * la plupart du temps, mais peut rendre un identifiant Windows
     * (« Romance Standard Time ») quand l'évènement porte le fuseau de son
     * auteur : PHP ne le connaît pas, et le laisser passer ferait échouer
     * l'analyse. On retombe alors sur UTC, la valeur que Graph documente par
     * défaut — jamais sur le fuseau du serveur, qui inventerait un décalage.
     */
    public static function graphTimeZone(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'UTC';
        }
        try {
            new \DateTimeZone($name);
            return $name;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    /** Corps Graph (Text ou HTML) → texte lisible, borné par l'appelant. */
    public static function plainText(string $content, string $contentType): string
    {
        if (trim($content) === '') {
            return '';
        }
        return strtolower($contentType) === 'html'
            ? UserOutlookService::htmlToText($content)
            : trim($content);
    }

    /** Minuscules sans accents — clé de comparaison du filtre texte. */
    public static function foldText(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'œ' => 'oe', '’' => "'", 'ʼ' => "'",
        ]);
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Aperçu HUMAIN de l'action « créer un évènement » pour la carte
     * d'approbation (mêmes clés que UserOutlookService::actionPreview + icone).
     *
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string,icone:string}
     */
    public static function actionPreview(string $tool, array $args): array
    {
        $titre = trim((string)($args['titre'] ?? '')) ?: t('(sans titre)');
        $quand = trim((string)($args['debut'] ?? ''));
        if (!empty($args['fin'])) {
            $quand .= ' → ' . trim((string)$args['fin']);
        }
        if (!empty($args['journee_entiere'])) {
            $quand .= ' ' . t('(journée entière)');
        } elseif ($quand !== '' && UserCalendarService::validTimeZone((string)($args['fuseau'] ?? '')) !== '') {
            $quand .= ' (' . (string)$args['fuseau'] . ')';
        }
        $agenda = trim((string)($args['agenda'] ?? ''));
        $lieu   = trim((string)($args['lieu'] ?? ''));
        $desc   = trim((string)($args['description'] ?? ''));

        $impacts = [
            $agenda !== ''
                ? t('Un évènement sera créé dans votre calendrier Outlook (agenda sélectionné).')
                : t('Un évènement sera créé dans votre calendrier Outlook principal.'),
            t('AUCUNE invitation ne sera envoyée : l\'évènement est créé sans participant.'),
            t('Vous pourrez le modifier ou le supprimer à tout moment dans Outlook.'),
        ];

        return [
            'action'  => t('Créer un évènement dans le calendrier Outlook'),
            'fichier' => $titre . ($quand !== '' ? ' — ' . $quand : '') . ($lieu !== '' ? ' — ' . $lieu : ''),
            'url'     => null,
            'impacts' => $impacts,
            'apercu'  => $desc === '' ? null : (mb_strlen($desc) > 280 ? mb_substr($desc, 0, 280) . '…' : $desc),
            'icone'   => '/assets/img/outlook-calendar.svg',
        ];
    }
}
