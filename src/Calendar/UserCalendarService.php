<?php

declare(strict_types=1);

/**
 * Accès à l'agenda Google PERSONNEL d'un utilisateur (OAuth par utilisateur,
 * tokens UserDriveToken provider 'google_calendar') : liste des agendas,
 * recherche et lecture d'évènements (scope calendar.readonly), création
 * d'évènements (scope calendar.events) — UNIQUEMENT via la file d'approbation,
 * et toujours avec `sendUpdates=none` : aucune invitation n'est envoyée
 * automatiquement aux participants. Aucune suppression d'évènement n'existe.
 *
 * Plomberie commune (refresh lazy, retry 401, erreurs FR sans fuite) :
 * GoogleUserApiService — ce fichier ne porte que le métier Agenda.
 */
class UserCalendarService extends GoogleUserApiService
{
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/calendar.readonly';
    public const SCOPE_EVENTS   = 'https://www.googleapis.com/auth/calendar.events';

    /** Clé Settings : interrupteur global admin de l'Agenda utilisateur dans le chat. */
    public const SETTING_ENABLED = 'user_calendar_enabled';

    /** Fenêtre par défaut d'une recherche sans dates : d'aujourd'hui à +90 jours. */
    private const DEFAULT_RANGE_DAYS = 90;

    public const MSG_RECONNECT = 'Connexion Google Agenda expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter son agenda dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_WRITE = 'Autorisation de création d\'évènements non accordée. '
        . 'L\'utilisateur doit reconnecter son agenda dans Réglages → Comptes connectés.';

    protected const OAUTH_CLASS       = GoogleCalendarOAuth::class;
    protected const TOKEN_PROVIDER    = UserDriveToken::PROVIDER_CALENDAR;
    protected const API_LABEL         = 'Google Agenda';
    protected const LOG_LABEL         = '[Agenda user]';
    protected const MSG_NOT_FOUND     = 'Agenda ou évènement introuvable (supprimé ou identifiant invalide).';
    protected const MSG_BAD_REQUEST   = 'Requête refusée par Google Agenda (HTTP 400) — vérifiez les dates et identifiants fournis.';
    protected const MSG_ACCESS_DENIED = 'Accès refusé par Google Agenda';
    protected const MSG_SCOPE_INSUFFICIENT = self::MSG_RECONNECT_WRITE;

    private const DAYS_FR = [
        1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi',
        5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
    ];

    /** Instance pour l'utilisateur, ou null si l'agenda n'est pas connecté (ou révoqué). */
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

    /** Lecture possible ? (calendar.readonly, ou calendar.events qui couvre la lecture des évènements) */
    public function canRead(): bool
    {
        return str_contains($this->grantedScope, self::SCOPE_READONLY)
            || str_contains($this->grantedScope, self::SCOPE_EVENTS);
    }

    /** Scope de création d'évènements accordé lors du consentement ? */
    public function canWrite(): bool
    {
        return str_contains($this->grantedScope, self::SCOPE_EVENTS);
    }

    /**
     * Fuseau de l'utilisateur pour CE tour (celui du navigateur) ; '' = celui
     * du serveur. Le serveur tourne en UTC : sans lui, « aujourd'hui » et les
     * bornes d'une recherche se calculaient en UTC, à deux heures près de Paris.
     */
    private string $timeZone = '';

    public function setTimeZone(string $timeZone): void
    {
        $this->timeZone = self::validTimeZone($timeZone);
    }

    /** Fuseau effectif : celui de l'utilisateur, sinon celui du serveur. */
    public function timeZoneName(): string
    {
        return $this->timeZone !== '' ? $this->timeZone : date_default_timezone_get();
    }

    /**
     * Occupations de l'agenda principal — et des participants nommés, quand
     * leur agenda est visible du compte — sur une fenêtre (API `freeBusy`).
     *
     * `freeBusy` ne rend que des plages OCCUPÉES, jamais le titre ni le
     * contenu d'un évènement : interroger l'agenda d'un collègue ne révèle
     * rien d'autre que ce que Google Agenda lui-même afficherait. Un évènement
     * marqué « disponible » n'y compte pas, comme dans l'interface Google.
     *
     * @param list<string> $participants adresses email
     * @return array{busy: list<array{0:\DateTimeImmutable,1:\DateTimeImmutable}>, visibles: list<string>, non_visibles: array<string,string>}
     */
    public function busyIntervals(\DateTimeImmutable $from, \DateTimeImmutable $to, array $participants = []): array
    {
        // Le compte lui-même n'est pas un « participant » : il est déjà
        // l'agenda principal, le lister deux fois ne ferait que doubler l'appel.
        $participants = array_values(array_filter(
            $participants,
            fn(string $p): bool => $this->accountEmail === '' || strcasecmp($p, $this->accountEmail) !== 0
        ));
        $items = [['id' => 'primary']];
        foreach ($participants as $p) {
            $items[] = ['id' => $p];
        }
        $data = $this->apiJson('POST', self::API_BASE . '/freeBusy', [
            'timeMin'  => $from->format(DATE_RFC3339),
            'timeMax'  => $to->format(DATE_RFC3339),
            'timeZone' => $this->timeZoneName(),
            'items'    => $items,
        ]);
        return self::parseFreeBusy($data, $participants);
    }

    /**
     * Liste des agendas visibles du compte (id, nom, principal, rôle).
     * Throw RuntimeException à message FR.
     */
    public function listCalendars(): array
    {
        $data = $this->apiJson(
            'GET',
            self::API_BASE . '/users/me/calendarList?maxResults=50'
                . '&fields=' . rawurlencode('items(id,summary,primary,accessRole)')
        );
        $out = [];
        foreach (($data['items'] ?? []) as $c) {
            $id = (string)($c['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id'        => $id,
                'nom'       => (string)($c['summary'] ?? $id),
                'principal' => !empty($c['primary']),
                'role'      => (string)($c['accessRole'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Recherche d'évènements (occurrences développées, ordre chronologique)
     * sur une fenêtre de dates, avec filtre texte optionnel (API `q` : titre,
     * description, lieu, participants). Dates absentes → d'aujourd'hui à
     * +DEFAULT_RANGE_DAYS jours. Retour : liste
     * `{id, agenda, titre, debut, fin, journee_entiere, lieu}`.
     * Throw RuntimeException à message FR.
     */
    public function searchEvents(string $calendarId, string $query, string $from, string $to, int $limit = 10): array
    {
        $calendarId = self::normalizeCalendarId($calendarId);
        $limit      = max(1, min(25, $limit));

        $fromDt = self::parseDateInput($from, false, $this->timeZone)
            ?? new \DateTimeImmutable('today', new \DateTimeZone($this->timeZoneName()));
        $toDt   = self::parseDateInput($to, true, $this->timeZone)
            ?? $fromDt->modify('+' . self::DEFAULT_RANGE_DAYS . ' days');
        if ($toDt <= $fromDt) {
            throw new \RuntimeException('La date de fin doit être postérieure à la date de début.');
        }

        $params = [
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => (string)$limit,
            'timeMin'      => $fromDt->format(DATE_RFC3339),
            'timeMax'      => $toDt->format(DATE_RFC3339),
            'fields'       => 'items(id,status,summary,location,start,end)',
        ];
        if (trim($query) !== '') {
            $params['q'] = trim($query);
        }

        $data = $this->apiJson(
            'GET',
            self::API_BASE . '/calendars/' . rawurlencode($calendarId) . '/events?' . http_build_query($params)
        );

        $out = [];
        foreach (($data['items'] ?? []) as $ev) {
            if (!is_array($ev) || (string)($ev['status'] ?? '') === 'cancelled') {
                continue;
            }
            $id = (string)($ev['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id'              => $id,
                'agenda'          => $calendarId,
                'titre'           => trim((string)($ev['summary'] ?? '')) ?: '(sans titre)',
                'debut'           => self::formatEventBoundary($ev['start'] ?? null),
                'fin'             => self::formatEventBoundary($ev['end'] ?? null),
                'journee_entiere' => isset($ev['start']['date']),
                'lieu'            => trim((string)($ev['location'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Lit un évènement complet : titre, dates, lieu, description (tronquée au
     * budget), participants (adresses, 20 max) et lien Google Agenda.
     * Throw RuntimeException à message FR.
     */
    public function readEvent(string $calendarId, string $eventId, int $tokenBudget): array
    {
        $calendarId = self::normalizeCalendarId($calendarId);
        $eventId    = trim($eventId);
        if (preg_match('/^[A-Za-z0-9_@.-]{5,}$/', $eventId) !== 1) {
            throw new \RuntimeException('Identifiant d\'évènement invalide — utilise rechercher_agenda d\'abord.');
        }

        $ev = $this->apiJson(
            'GET',
            self::API_BASE . '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId)
        );

        $desc = trim(html_entity_decode(strip_tags((string)($ev['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $maxChars  = max(1000, $tokenBudget * 4);
        $truncated = mb_strlen($desc) > $maxChars;
        if ($truncated) {
            $desc = mb_substr($desc, 0, $maxChars) . "\n[… contenu tronqué]";
        }

        $attendees = [];
        foreach (array_slice((array)($ev['attendees'] ?? []), 0, 20) as $a) {
            if (!is_array($a) || (string)($a['email'] ?? '') === '') {
                continue;
            }
            $attendees[] = (string)$a['email']
                . (!empty($a['organizer']) ? ' (organisateur)' : '')
                . (($a['responseStatus'] ?? '') === 'declined' ? ' (décliné)' : '');
        }

        return [
            'titre'           => trim((string)($ev['summary'] ?? '')) ?: '(sans titre)',
            'debut'           => self::formatEventBoundary($ev['start'] ?? null),
            'fin'             => self::formatEventBoundary($ev['end'] ?? null),
            'journee_entiere' => isset($ev['start']['date']),
            'lieu'            => trim((string)($ev['location'] ?? '')),
            'description'     => $desc,
            'participants'    => $attendees,
            'lien'            => (string)($ev['htmlLink'] ?? ''),
            'tronque'         => $truncated,
        ];
    }

    /**
     * Crée un évènement (appelé uniquement depuis la file d'approbation, au
     * clic « Approuver »). `sendUpdates=none` : les participants éventuels
     * sont ajoutés SANS envoi d'invitation.
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

        $ev = $this->apiJson(
            'POST',
            self::API_BASE . '/calendars/' . rawurlencode($calendarId) . '/events?sendUpdates=none',
            $payload
        );
        if ((string)($ev['id'] ?? '') === '') {
            throw new \RuntimeException('Création de l\'évènement refusée par Google Agenda.');
        }
        // Trace de succès (id seul, jamais de contenu) — diagnostic staging.
        error_log('[Agenda user] évènement créé id=' . (string)$ev['id']
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return [
            'id'     => (string)$ev['id'],
            'titre'  => trim((string)($ev['summary'] ?? ($args['titre'] ?? ''))) ?: '(sans titre)',
            'quand'  => self::formatEventBoundary($ev['start'] ?? null),
            'url'    => (string)($ev['htmlLink'] ?? 'https://calendar.google.com/'),
            'agenda' => $calendarId,
        ];
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /** Id d'agenda nettoyé ; vide ou 'principal' → 'primary'. */
    public static function normalizeCalendarId(string $id): string
    {
        $id = trim($id);
        if ($id === '' || strcasecmp($id, 'primary') === 0 || strcasecmp($id, 'principal') === 0) {
            return 'primary';
        }
        if (preg_match('#^[A-Za-z0-9._%\#+@-]{3,320}$#', $id) !== 1) {
            throw new \RuntimeException('Identifiant d\'agenda invalide — utilise lister_agendas d\'abord.');
        }
        return $id;
    }

    /**
     * Fuseau IANA valide, ou '' (= celui du serveur). Jamais d'exception : un
     * fuseau inconnu ne doit pas faire échouer une lecture d'agenda.
     */
    public static function validTimeZone(string $timeZone): string
    {
        $timeZone = trim($timeZone);
        if ($timeZone === '' || strlen($timeZone) > 64) {
            return '';
        }
        try {
            new \DateTimeZone($timeZone);
            return $timeZone;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Fuseau dans lequel lire les heures d'une création d'évènement : celui de
     * l'utilisateur, posé par l'outil dans les arguments de l'action (`fuseau`),
     * sinon celui du serveur. L'action s'exécute plus tard, au clic
     * « Approuver », dans une requête qui ne connaît plus le navigateur : le
     * fuseau DOIT voyager avec elle, sinon « demain 14 h » était créé à 14 h
     * UTC — 16 h à Paris.
     */
    public static function argTimeZone(array $args): string
    {
        $tz = self::validTimeZone(is_scalar($args['fuseau'] ?? null) ? (string)$args['fuseau'] : '');
        return $tz !== '' ? $tz : date_default_timezone_get();
    }

    /**
     * Occupations rendues par `freeBusy`. Toute entrée de `calendars` qui n'est
     * pas un participant nommé est l'agenda du compte (Google la rend sous
     * « primary » ou sous l'adresse réelle selon les cas). Un agenda en erreur
     * (introuvable, non partagé) est nommé, jamais compté comme libre.
     *
     * @param list<string> $participants
     * @return array{busy: list<array{0:\DateTimeImmutable,1:\DateTimeImmutable}>, visibles: list<string>, non_visibles: array<string,string>}
     */
    public static function parseFreeBusy(array $data, array $participants): array
    {
        $byLower = [];
        foreach ($participants as $p) {
            $byLower[strtolower($p)] = $p;
        }
        $busy = [];
        $visibles = [];
        $nonVisibles = [];
        foreach ((array)($data['calendars'] ?? []) as $id => $cal) {
            if (!is_array($cal)) {
                continue;
            }
            $participant = $byLower[strtolower((string)$id)] ?? null;
            $errors = (array)($cal['errors'] ?? []);
            if ($errors !== []) {
                if ($participant !== null) {
                    $nonVisibles[$participant] = self::freeBusyReason((string)($errors[0]['reason'] ?? ''));
                }
                continue;
            }
            if ($participant !== null) {
                $visibles[] = $participant;
            }
            foreach ((array)($cal['busy'] ?? []) as $b) {
                try {
                    $s = new \DateTimeImmutable((string)($b['start'] ?? ''));
                    $e = new \DateTimeImmutable((string)($b['end'] ?? ''));
                } catch (\Throwable) {
                    continue;
                }
                if ($e > $s) {
                    $busy[] = [$s, $e];
                }
            }
        }
        // Participant absent de la réponse : on ne sait rien de lui, on le dit.
        foreach ($participants as $p) {
            if (!in_array($p, $visibles, true) && !isset($nonVisibles[$p])) {
                $nonVisibles[$p] = 'agenda non communiqué par Google';
            }
        }
        return ['busy' => $busy, 'visibles' => $visibles, 'non_visibles' => $nonVisibles];
    }

    /** Raison `freeBusy` → texte FR (ce que le modèle dira à l'utilisateur). */
    private static function freeBusyReason(string $reason): string
    {
        return match ($reason) {
            'notFound'                  => 'agenda introuvable ou non partagé avec ce compte',
            'groupTooBig'               => 'groupe trop grand pour être consulté',
            'tooManyCalendarsRequested' => 'trop d\'agendas demandés à la fois',
            default                     => 'agenda non consultable',
        };
    }

    /**
     * Saisie de date du modèle → DateTimeImmutable, ou null si vide. Formats :
     * 'YYYY-MM-DD' (jour entier — $endOfDay pour borner une fin de fenêtre au
     * lendemain 00:00), 'YYYY-MM-DD HH:MM' ou RFC3339. Fuseau : `$timeZone`
     * (celui de l'utilisateur) s'il est valide, sinon celui du serveur.
     * Throw RuntimeException si illisible.
     */
    public static function parseDateInput(string $raw, bool $endOfDay, string $timeZone = ''): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $timeZone = self::validTimeZone($timeZone);
        $tz = new \DateTimeZone($timeZone !== '' ? $timeZone : date_default_timezone_get());
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);
            if ($d === false || $d->format('Y-m-d') !== $raw) {
                throw new \RuntimeException('Date invalide : « ' . $raw . ' » (format attendu : AAAA-MM-JJ).');
            }
            return $endOfDay ? $d->modify('+1 day') : $d;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $raw) === 1) {
            try {
                return new \DateTimeImmutable(str_replace(' ', 'T', $raw), $tz);
            } catch (\Throwable) {
                // Illisible : throw commun ci-dessous.
            }
        }
        throw new \RuntimeException('Date invalide : « ' . $raw . ' » (formats acceptés : AAAA-MM-JJ ou AAAA-MM-JJ HH:MM).');
    }

    /**
     * Arguments de l'outil creer_evenement_agenda → corps de l'API events.insert.
     * Journée entière (journee_entiere=true OU dates sans heure) → start/end.date
     * (fin EXCLUSIVE : +1 jour) ; sinon start/end.dateTime au fuseau serveur
     * (fin absente → début + 1 h). Participants validés (FILTER_VALIDATE_EMAIL).
     * Throw RuntimeException à message FR sur toute entrée invalide.
     */
    public static function buildEventPayload(array $args): array
    {
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
        $allDay  = !empty($args['journee_entiere']) || ($dayOnly && ($finRaw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $finRaw) === 1));

        // Heures lues dans le fuseau de l'UTILISATEUR (cf. argTimeZone).
        $tzName = self::argTimeZone($args);
        $payload = ['summary' => $titre];

        if ($allDay) {
            if (!$dayOnly) {
                throw new \RuntimeException('Un évènement « journée entière » attend des dates sans heure (AAAA-MM-JJ).');
            }
            $start = self::parseDateInput($debutRaw, false, $tzName);
            $end   = $finRaw !== '' ? self::parseDateInput($finRaw, false, $tzName) : $start;
            if ($end < $start) {
                throw new \RuntimeException('La date de fin doit être postérieure ou égale à la date de début.');
            }
            // Fin exclusive côté Google : lendemain du dernier jour.
            $payload['start'] = ['date' => $start->format('Y-m-d')];
            $payload['end']   = ['date' => $end->modify('+1 day')->format('Y-m-d')];
        } else {
            $start = self::parseDateInput($debutRaw, false, $tzName);
            if ($start === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $debutRaw) === 1) {
                throw new \RuntimeException('Heure de début manquante (format AAAA-MM-JJ HH:MM), ou passe journee_entiere=true.');
            }
            $end = $finRaw !== '' ? self::parseDateInput($finRaw, false, $tzName) : $start->modify('+1 hour');
            if ($end === null || $end <= $start) {
                throw new \RuntimeException('L\'heure de fin doit être postérieure à l\'heure de début.');
            }
            $payload['start'] = ['dateTime' => $start->format(DATE_RFC3339), 'timeZone' => $tzName];
            $payload['end']   = ['dateTime' => $end->format(DATE_RFC3339), 'timeZone' => $tzName];
        }

        $desc = trim((string)($args['description'] ?? ''));
        if ($desc !== '') {
            $payload['description'] = mb_substr($desc, 0, 8000);
        }
        $lieu = trim((string)($args['lieu'] ?? ''));
        if ($lieu !== '') {
            $payload['location'] = mb_substr($lieu, 0, 1000);
        }

        $invites = trim((string)($args['invites'] ?? ''));
        if ($invites !== '') {
            $attendees = [];
            foreach (explode(',', $invites) as $addr) {
                $addr = trim($addr);
                if ($addr === '') {
                    continue;
                }
                if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \RuntimeException('Adresse de participant invalide : « ' . $addr . ' »');
                }
                $attendees[] = ['email' => $addr];
            }
            if ($attendees !== []) {
                $payload['attendees'] = array_slice($attendees, 0, 50);
            }
        }
        return $payload;
    }

    /**
     * Borne start/end d'un évènement Google → texte FR lisible :
     * « vendredi 07/08/2026 09:00 » (dateTime, affiché dans le fuseau porté
     * par l'horodatage) ou « vendredi 07/08/2026 (journée entière) » (date).
     */
    public static function formatEventBoundary(mixed $node): string
    {
        if (!is_array($node)) {
            return '';
        }
        try {
            if (isset($node['dateTime'])) {
                $d = new \DateTimeImmutable((string)$node['dateTime']);
                return self::DAYS_FR[(int)$d->format('N')] . ' ' . $d->format('d/m/Y H:i');
            }
            if (isset($node['date'])) {
                $d = new \DateTimeImmutable((string)$node['date']);
                return self::DAYS_FR[(int)$d->format('N')] . ' ' . $d->format('d/m/Y') . ' (journée entière)';
            }
        } catch (\Throwable) {
            // Horodatage illisible : chaîne brute plutôt que crash.
            return (string)($node['dateTime'] ?? $node['date'] ?? '');
        }
        return '';
    }

    /**
     * Aperçu HUMAIN de l'action « créer un évènement » pour la carte
     * d'approbation (mêmes clés que UserGmailService::actionPreview).
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
        } elseif ($quand !== '' && self::validTimeZone((string)($args['fuseau'] ?? '')) !== '') {
            // Le fuseau des heures affichées, nommé : la carte ne doit laisser
            // aucun doute sur l'heure qui sera réellement bloquée.
            $quand .= ' (' . (string)$args['fuseau'] . ')';
        }
        $agenda  = trim((string)($args['agenda'] ?? ''));
        $lieu    = trim((string)($args['lieu'] ?? ''));
        $invites = trim((string)($args['invites'] ?? ''));
        $desc    = trim((string)($args['description'] ?? ''));

        $impacts = [
            $agenda !== '' && strcasecmp($agenda, 'primary') !== 0
                ? t('Un évènement sera créé dans votre agenda Google (agenda : {agenda}).', ['agenda' => $agenda])
                : t('Un évènement sera créé dans votre agenda Google principal.'),
        ];
        if ($invites !== '') {
            $impacts[] = t('Participants ajoutés SANS envoi d\'invitation : {participants}.', ['participants' => $invites]);
        }
        $impacts[] = t('Vous pourrez le modifier ou le supprimer à tout moment dans Google Agenda.');

        return [
            'action'  => t('Créer un évènement Google Agenda'),
            'fichier' => $titre . ($quand !== '' ? ' — ' . $quand : '') . ($lieu !== '' ? ' — ' . $lieu : ''),
            'url'     => null,
            'impacts' => $impacts,
            'apercu'  => $desc === '' ? null : (mb_strlen($desc) > 280 ? mb_substr($desc, 0, 280) . '…' : $desc),
            'icone'   => '/assets/img/google-calendar.svg',
        ];
    }

}
