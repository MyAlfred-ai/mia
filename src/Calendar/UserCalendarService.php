<?php

declare(strict_types=1);

/**
 * Accès à l'agenda Google PERSONNEL d'un utilisateur (OAuth par utilisateur,
 * tokens UserDriveToken provider 'google_calendar') : liste des agendas,
 * recherche et lecture d'évènements (scope calendar.readonly), création ET
 * modification d'évènements (scope calendar.events) — invités obligatoires et
 * facultatifs, récurrence, visibilité, disponibilité —, ces deux écritures
 * UNIQUEMENT via la file d'approbation. Aucune suppression ni annulation
 * d'évènement n'existe.
 *
 * Plomberie commune (refresh lazy, retry 401, erreurs FR sans fuite) :
 * GoogleUserApiService — ce fichier ne porte que le métier Agenda.
 *
 * ── CE QUE DIT LA DOC GOOGLE (référence Events + guides, relus le 2026-11-18)
 *
 *  1. **Inviter, c'est écrire, même sans email.** « The event you create
 *     appears on all the primary Google Calendars of the attendees you
 *     included » ; `sendUpdates` ne décide que de l'EMAIL (`all` = tous,
 *     `none` = aucun). Par défaut MIA envoie l'invitation (une personne
 *     ajoutée à une réunion s'attend à la recevoir), `envoyer_invitations:
 *     false` ajoute sans email — une adresse hors Google n'est alors prévenue
 *     de rien. Dans les deux cas la carte nomme chaque invité, et une tâche
 *     « sans validation » sans droit d'envoi dépose une carte
 *     (`notifiesAttendees`).
 *
 *  2. **Récurrence = lignes RFC 5545** dans `recurrence[]` (RRULE, EXDATE ;
 *     jamais DTSTART : c'est `start`). « For recurring events [start.timeZone]
 *     is required » : posé aussi sur une journée entière récurrente. `UNTIL`
 *     suit RFC 5545 : une date (AAAAMMJJ) pour une journée entière, un
 *     horodatage UTC pour un évènement horodaté. Le début doit être une
 *     occurrence de la règle (sinon l'ensemble est « undefined » selon la
 *     RFC) : vérifié avant la carte.
 *
 *  3. **`visibility`** : default / public / private (confidential = private,
 *     pour compatibilité). Sur une occurrence, une visibilité plus stricte
 *     s'étend à toute la série et une moins stricte est IGNORÉE : la
 *     visibilité d'une réunion récurrente se règle donc sur la série.
 *
 *  4. **`transparency`** : `opaque` (occupé, défaut) / `transparent`
 *     (disponible : le créneau n'est pas bloqué).
 *
 *  5. **PATCH : un tableau envoyé remplace le tableau entier** (« Array
 *     fields, if specified, overwrite the existing arrays »). Les invités
 *     sont donc RECOMPOSÉS sur l'évènement relu — envoyer les seuls nouveaux
 *     retirerait les autres. Les entrées existantes repartent telles quelles
 *     (réponses des invités gardées).
 *
 *  6. **Propriétés partagées = organisateur seulement** (erreur 403
 *     `forbiddenForNonOrganizer`). Qui n'organise pas (et sans
 *     `guestsCanModify`) ne règle que la visibilité et la disponibilité de
 *     son exemplaire : refus expliqué AVANT la carte.
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

    /** Outils d'écriture (file d'approbation). */
    public const TOOL_CREATE = 'creer_evenement_agenda';
    public const TOOL_UPDATE = 'modifier_evenement_agenda';

    /** Invités d'un évènement, au plus (au-delà : une liste de diffusion). */
    public const MAX_ATTENDEES = 50;

    /** Visibilité (`visibility`, piège 3) : mot de l'outil → valeur Google. */
    public const VISIBILITY = ['par_defaut' => 'default', 'publique' => 'public', 'privee' => 'private'];
    /** Disponibilité affichée (`transparency`, piège 4) : mot de l'outil → valeur Google. */
    public const AVAILABILITY = ['occupe' => 'opaque', 'disponible' => 'transparent'];
    /** Façons d'écrire ces deux réglages, ramenées au mot de l'outil. */
    private const SETTING_ALIASES = [
        'visibilite'    => [
            'defaut' => 'par_defaut', 'default' => 'par_defaut', 'normale' => 'par_defaut',
            'public' => 'publique', 'visible' => 'publique',
            'prive' => 'privee', 'private' => 'privee', 'confidentiel' => 'privee', 'confidentielle' => 'privee',
            'confidential' => 'privee',
        ],
        'disponibilite' => [
            'occupee' => 'occupe', 'busy' => 'occupe', 'opaque' => 'occupe',
            'libre' => 'disponible', 'available' => 'disponible', 'free' => 'disponible', 'transparent' => 'disponible',
        ],
    ];

    /** Récurrences proposées (même vocabulaire que le calendrier Outlook). */
    public const RECURRENCES = ['quotidienne', 'jours_ouvres', 'hebdomadaire', 'mensuelle', 'annuelle'];
    private const RECURRENCE_ALIASES = [
        'quotidien' => 'quotidienne', 'chaque_jour' => 'quotidienne', 'tous_les_jours' => 'quotidienne', 'daily' => 'quotidienne',
        'jours_ouvrables' => 'jours_ouvres', 'jours_de_semaine' => 'jours_ouvres', 'du_lundi_au_vendredi' => 'jours_ouvres',
        'weekdays' => 'jours_ouvres',
        'hebdo' => 'hebdomadaire', 'chaque_semaine' => 'hebdomadaire', 'weekly' => 'hebdomadaire',
        'mensuel' => 'mensuelle', 'chaque_mois' => 'mensuelle', 'monthly' => 'mensuelle',
        'annuel' => 'annuelle', 'chaque_annee' => 'annuelle', 'yearly' => 'annuelle', 'annually' => 'annuelle',
    ];
    /** Récurrence mensuelle : le même quantième, le même rang du jour, ou le dernier de ce jour. */
    public const MONTHLY_MODES = ['meme_date', 'meme_jour_semaine', 'dernier_jour_semaine'];
    private const MONTHLY_ALIASES = [
        'date' => 'meme_date', 'quantieme' => 'meme_date', 'jour_du_mois' => 'meme_date',
        'jour_semaine' => 'meme_jour_semaine', 'position' => 'meme_jour_semaine', 'rang' => 'meme_jour_semaine',
        'dernier' => 'dernier_jour_semaine', 'last' => 'dernier_jour_semaine',
    ];
    /** Jour ISO (1 = lundi) → jour RFC 5545. */
    private const RRULE_DAYS = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];
    /** Noms de jours acceptés dans `recurrence_jours` (minuscules, sans accents). */
    private const DAY_NAMES = [
        'lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7,
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
    ];
    /** Nombre d'occurrences au plus d'une série « N fois ». */
    private const MAX_OCCURRENCES = 999;
    /** Dates exclues d'une série, au plus. */
    private const MAX_EXCEPTIONS = 30;

    public const MSG_RECONNECT = 'Connexion Google Agenda expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter son agenda dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_WRITE = 'Autorisation de création d\'évènements non accordée. '
        . 'L\'utilisateur doit reconnecter son agenda dans Réglages → Comptes connectés.';
    /** 403 `forbiddenForNonOrganizer` (piège 6), traduit. */
    public const MSG_NOT_ORGANIZER = 'Google Agenda refuse : seul l\'organisateur de cet évènement peut modifier son titre, '
        . 'son horaire, son lieu, sa description ou ses invités.';

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

    /**
     * 403 propre à l'agenda : propriété partagée modifiée par qui n'organise
     * pas (piège 6). Consulté avant le socle Google (scope, API désactivée).
     */
    protected function specificHttpError(int $status, string $body): ?\RuntimeException
    {
        if ($status === 403 && str_contains($body, 'forbiddenForNonOrganizer')) {
            return new \RuntimeException(self::MSG_NOT_ORGANIZER);
        }
        return null;
    }

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
            'fields'       => 'items(id,status,summary,location,start,end,recurringEventId)',
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
                // Occurrence d'une réunion récurrente : l'id de la SÉRIE
                // (pour modifier toute la série, cf. modifier_evenement_agenda).
                'serie'           => (string)($ev['recurringEventId'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Lit un évènement complet : titre, dates, lieu, description (tronquée au
     * budget), participants (adresses, 20 max), récurrence, visibilité,
     * disponibilité, qui organise et lien Google Agenda.
     * Throw RuntimeException à message FR.
     */
    public function readEvent(string $calendarId, string $eventId, int $tokenBudget): array
    {
        $calendarId = self::normalizeCalendarId($calendarId);
        $eventId    = self::assertEventId($eventId);

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
                . (!empty($a['optional']) ? ' (facultatif)' : '')
                . (!empty($a['resource']) ? ' (ressource)' : '')
                . match ((string)($a['responseStatus'] ?? '')) {
                    'accepted'  => ' (accepté)',
                    'declined'  => ' (décliné)',
                    'tentative' => ' (peut-être)',
                    default     => '',
                };
        }
        $organizer = is_array($ev['organizer'] ?? null) ? $ev['organizer'] : [];

        return [
            'id'              => (string)($ev['id'] ?? $eventId),
            'titre'           => trim((string)($ev['summary'] ?? '')) ?: '(sans titre)',
            'debut'           => self::formatEventBoundary($ev['start'] ?? null),
            'fin'             => self::formatEventBoundary($ev['end'] ?? null),
            'journee_entiere' => isset($ev['start']['date']),
            'lieu'            => trim((string)($ev['location'] ?? '')),
            'description'     => $desc,
            'participants'    => $attendees,
            'organisateur'    => (string)($organizer['email'] ?? ''),
            'vous_organisez'  => !empty($organizer['self']),
            // Série : `recurrence` sur l'évènement-série, `recurringEventId`
            // sur une occurrence (l'id de la série, pour la modifier entière).
            'recurrence'      => array_values(array_filter((array)($ev['recurrence'] ?? []), 'is_string')),
            'serie'           => (string)($ev['recurringEventId'] ?? ''),
            'visibilite'      => self::settingWord(self::VISIBILITY, (string)($ev['visibility'] ?? 'default'), 'par_defaut'),
            'disponibilite'   => self::settingWord(self::AVAILABILITY, (string)($ev['transparency'] ?? 'opaque'), 'occupe'),
            'lien'            => (string)($ev['htmlLink'] ?? ''),
            'tronque'         => $truncated,
        ];
    }

    /**
     * Crée un évènement (appelé uniquement depuis la file d'approbation, au
     * clic « Approuver »). Avec des invités, Google les ajoute à l'évènement
     * — il apparaît dans leur agenda Google — et, sauf `envoyer_invitations:
     * false`, leur envoie l'invitation par email (piège 1) : la carte l'a dit.
     *
     * @return array{id:string,titre:string,quand:string,url:string,agenda:string,invites:list<string>,invitations_envoyees:bool,recurrent:bool}
     */
    public function createEvent(array $args): array
    {
        if (!$this->canWrite()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $calendarId = self::normalizeCalendarId((string)($args['agenda'] ?? ''));
        $payload    = self::buildEventPayload($args, $this->accountEmail);
        $invites    = self::attendeeEmails((array)($payload['attendees'] ?? []));
        $send       = $invites !== [] && self::wantsInvitations($args);

        $ev = $this->apiJson(
            'POST',
            self::API_BASE . '/calendars/' . rawurlencode($calendarId) . '/events?sendUpdates=' . ($send ? 'all' : 'none'),
            $payload
        );
        if ((string)($ev['id'] ?? '') === '') {
            throw new \RuntimeException('Création de l\'évènement refusée par Google Agenda.');
        }
        // Trace de succès (id seul, jamais de contenu) — diagnostic staging.
        error_log('[Agenda user] évènement créé id=' . (string)$ev['id']
            . ' invités=' . count($invites) . ' invitations=' . ($send ? 'oui' : 'non')
            . (isset($payload['recurrence']) ? ' récurrent' : '')
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return [
            'id'     => (string)$ev['id'],
            'titre'  => trim((string)($ev['summary'] ?? ($args['titre'] ?? ''))) ?: '(sans titre)',
            'quand'  => self::formatEventBoundary($ev['start'] ?? null, self::argTimeZone($args)),
            'url'    => (string)($ev['htmlLink'] ?? 'https://calendar.google.com/'),
            'agenda' => $calendarId,
            'invites' => $invites,
            'invitations_envoyees' => $send,
            'recurrent' => isset($payload['recurrence']),
        ];
    }

    /**
     * Évènement visé par une modification, tel qu'il est : l'occurrence, ou
     * la SÉRIE entière quand `toute_la_serie` est demandé sur une occurrence.
     * Throw RuntimeException à message FR.
     *
     * @return array{0: array<string,mixed>, 1: string} [évènement, portée : 'serie' | 'occurrence' | '']
     */
    public function fetchForUpdate(string $calendarId, string $eventId, bool $wholeSeries): array
    {
        $calendarId = self::normalizeCalendarId($calendarId);
        $id = self::assertEventId($eventId);
        $ev = $this->apiJson('GET', self::API_BASE . '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($id));
        if ((string)($ev['id'] ?? '') === '') {
            throw new \RuntimeException(self::MSG_NOT_FOUND);
        }
        $seriesId = (string)($ev['recurringEventId'] ?? '');
        if ($seriesId !== '' && $wholeSeries) {
            $ev = $this->apiJson('GET', self::API_BASE . '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode(self::assertEventId($seriesId)));
            if ((string)($ev['id'] ?? '') === '') {
                throw new \RuntimeException(self::MSG_NOT_FOUND);
            }
            return [$ev, 'serie'];
        }
        if ($seriesId !== '') {
            return [$ev, 'occurrence'];
        }
        return [$ev, (array)($ev['recurrence'] ?? []) !== [] ? 'serie' : ''];
    }

    /**
     * Contrôle d'une modification AVANT la carte (appelé par le handler) :
     * l'évènement est relu, la demande validée contre son état, et la carte
     * reçoit de quoi le nommer et dire qui sera prévenu.
     *
     * @return array{titre:string,quand:string,lien:string,portee:string,organisateur:bool,previent:bool,touche_invites:bool,invites:list<string>,ajoutes:list<string>,retires:list<string>}
     */
    public function inspectUpdate(array $args): array
    {
        if (!$this->canWrite()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        [$current, $scope] = $this->fetchForUpdate(
            (string)($args['agenda'] ?? ''),
            (string)($args['evenement'] ?? ''),
            self::truthy($args['toute_la_serie'] ?? null)
        );
        $plan = self::buildUpdatePayload($args, $current, $scope, $this->accountEmail);

        return [
            'titre'    => trim((string)($current['summary'] ?? '')) ?: '(sans titre)',
            'quand'    => self::formatEventBoundary($current['start'] ?? null, self::argTimeZone($args)),
            'lien'     => preg_match('#^https://#i', (string)($current['htmlLink'] ?? '')) === 1 ? (string)$current['htmlLink'] : '',
            'portee'   => $scope,
            'organisateur'   => $plan['organisateur'],
            'previent'       => $plan['previent'],
            'touche_invites' => $plan['touche_invites'],
            'invites'  => $plan['invites'],
            'ajoutes'  => $plan['ajoutes'],
            'retires'  => $plan['retires'],
        ];
    }

    /**
     * Modifie un évènement (file d'approbation, au clic « Approuver »).
     * L'évènement est RELU à l'exécution : les invités sont recomposés sur
     * son état du moment, pas sur celui d'avant la carte (piège 5).
     *
     * @return array{id:string,titre:string,quand:string,url:string,portee:string,previent:bool,invites:list<string>,ajoutes:list<string>,retires:list<string>}
     */
    public function updateEvent(array $args): array
    {
        if (!$this->canWrite()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $calendarId = self::normalizeCalendarId((string)($args['agenda'] ?? ''));
        [$current, $scope] = $this->fetchForUpdate(
            $calendarId,
            (string)($args['evenement'] ?? ''),
            self::truthy($args['toute_la_serie'] ?? null)
        );
        $plan = self::buildUpdatePayload($args, $current, $scope, $this->accountEmail);
        $id   = (string)$current['id'];

        $ev = $this->apiJson(
            'PATCH',
            self::API_BASE . '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($id)
                . '?sendUpdates=' . ($plan['previent'] ? 'all' : 'none'),
            $plan['payload']
        );
        // Google rend l'évènement à jour : sans lui, rien ne prouve que la
        // modification a eu lieu — on ne l'annonce pas.
        if ((string)($ev['id'] ?? '') === '') {
            throw new \RuntimeException('Modification refusée par Google Agenda : l\'évènement n\'a pas été rendu à jour.');
        }
        error_log('[Agenda user] évènement modifié id=' . mb_substr($id, 0, 40)
            . ' champs=' . implode(',', array_keys($plan['payload']))
            . ' invitations=' . ($plan['previent'] ? 'oui' : 'non')
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return [
            'id'       => (string)$ev['id'],
            'titre'    => trim((string)($ev['summary'] ?? ($current['summary'] ?? ''))) ?: '(sans titre)',
            'quand'    => self::formatEventBoundary($ev['start'] ?? ($current['start'] ?? null), self::argTimeZone($args)),
            'url'      => (string)($ev['htmlLink'] ?? ($current['htmlLink'] ?? 'https://calendar.google.com/')),
            'portee'   => $scope,
            'previent' => $plan['previent'],
            'invites'  => $plan['invites'],
            'ajoutes'  => $plan['ajoutes'],
            'retires'  => $plan['retires'],
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
     * (fin EXCLUSIVE : +1 jour) ; sinon start/end.dateTime au fuseau de
     * l'utilisateur (fin absente → début + 1 h). Invités obligatoires et
     * facultatifs (adresses validées, organisateur écarté), récurrence
     * (RRULE/EXDATE), visibilité, disponibilité : cf. les fonctions dédiées.
     * Throw RuntimeException à message FR sur toute entrée invalide — avant la
     * carte, jamais après l'approbation.
     */
    public static function buildEventPayload(array $args, string $accountEmail = ''): array
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
        $dayOnly = self::isDayOnly($debutRaw);
        $allDay  = self::truthy($args['journee_entiere'] ?? null) || ($dayOnly && ($finRaw === '' || self::isDayOnly($finRaw)));

        // Heures lues dans le fuseau de l'UTILISATEUR (cf. argTimeZone).
        $tzName = self::argTimeZone($args);
        $payload = ['summary' => mb_substr($titre, 0, 1024)];

        if ($allDay) {
            if (!$dayOnly || ($finRaw !== '' && !self::isDayOnly($finRaw))) {
                throw new \RuntimeException('Un évènement « journée entière » attend des dates sans heure (AAAA-MM-JJ).');
            }
            $start = self::parseDateInput($debutRaw, false, $tzName);
            $end   = $finRaw !== '' ? self::parseDateInput($finRaw, false, $tzName) : $start;
            if ($start === null || $end === null || $end < $start) {
                throw new \RuntimeException('La date de fin doit être postérieure ou égale à la date de début.');
            }
            // Fin exclusive côté Google : lendemain du dernier jour.
            $payload['start'] = ['date' => $start->format('Y-m-d')];
            $payload['end']   = ['date' => $end->modify('+1 day')->format('Y-m-d')];
        } else {
            $start = self::parseDateInput($debutRaw, false, $tzName);
            if ($start === null || $dayOnly) {
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

        // Invités : `participants` reste lu (ancien nom, et celui que le modèle
        // emploie spontanément) — l'ignorer laisserait croire des invités conviés.
        $attendees = self::attendeeList(
            self::parseAddresses($args['invites'] ?? ($args['participants'] ?? null)),
            self::parseAddresses($args['invites_optionnels'] ?? null),
            $accountEmail
        );
        if (count($attendees) > self::MAX_ATTENDEES) {
            throw new \RuntimeException('Trop d\'invités (' . count($attendees) . ') : ' . self::MAX_ATTENDEES
                . ' au plus par évènement. Invite une liste de diffusion, ou réduis la liste.');
        }
        if ($attendees !== []) {
            $payload['attendees'] = $attendees;
        }

        $payload += self::optionsPayload($args);

        $spec = self::recurrenceSpec($args, $start);
        if ($spec !== null) {
            $payload['recurrence'] = self::rruleLines($spec, $start, $allDay, $tzName);
            // « For recurring events this field is required » (piège 2) —
            // une journée entière comprise.
            $payload['start']['timeZone'] = $tzName;
            $payload['end']['timeZone']   = $tzName;
        }
        return $payload;
    }

    /**
     * Arguments de l'outil modifier_evenement_agenda + évènement tel qu'il est
     * (`fetchForUpdate`) → corps du PATCH, limité à ce qui CHANGE.
     *
     *  - horaire : `debut` seul garde la DURÉE ; une date sans heure déplace le
     *    jour en gardant l'heure ; une heure seule (« HH:MM ») garde le jour.
     *    Sur toute une SÉRIE, seule l'heure se change : déplacer ses jours se
     *    fait dans Google Agenda ;
     *  - invités : la liste envoyée est la liste ENTIÈRE recomposée (piège 5) ;
     *  - qui n'organise pas ne règle que visibilité et disponibilité (piège 6) ;
     *  - visibilité d'une réunion récurrente : sur la série (piège 3).
     *
     * `previent` : Google écrira-t-il aux invités (`sendUpdates=all`) ?
     * `touche_invites` : le changement les concerne-t-il, email ou pas (carte,
     * tâches sans validation) ? Throw RuntimeException à message FR.
     *
     * @return array{payload: array<string,mixed>, previent: bool, touche_invites: bool, organisateur: bool, invites: list<string>, ajoutes: list<string>, retires: list<string>}
     */
    public static function buildUpdatePayload(array $args, array $current, string $scope = '', string $accountEmail = ''): array
    {
        if ((string)($current['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Cet évènement est annulé : il ne se modifie plus.');
        }
        $has = static function (string $k) use ($args): bool {
            $v = $args[$k] ?? null;
            if (is_array($v)) {
                return $v !== [];
            }
            return is_scalar($v) && !is_bool($v) && trim((string)$v) !== '';
        };
        $wantAllDay = self::truthy($args['journee_entiere'] ?? null);

        if (self::recurrenceType($args['recurrence'] ?? null) !== '') {
            throw new \RuntimeException('La répétition d\'une réunion existante se change dans Google Agenda : cet outil '
                . 'en modifie le titre, l\'horaire, le lieu, la description, les invités, la visibilité et la disponibilité.');
        }

        $organizerNode = is_array($current['organizer'] ?? null) ? $current['organizer'] : [];
        $organizer = $organizerNode === [] || !empty($organizerNode['self']);
        $canModify = $organizer || !empty($current['guestsCanModify']);
        $content   = array_filter(
            ['titre', 'debut', 'fin', 'lieu', 'description', 'ajouter_invites', 'ajouter_invites_optionnels', 'retirer_invites'],
            $has
        );
        if (!$canModify && ($content !== [] || $wantAllDay)) {
            $qui = trim((string)($organizerNode['email'] ?? ''));
            throw new \RuntimeException(
                'Seul l\'organisateur' . ($qui !== '' ? ' (' . $qui . ')' : '') . ' peut modifier le titre, l\'horaire, '
                . 'le lieu, la description ou les invités de cet évènement. Dans l\'agenda de l\'utilisateur, tu peux '
                . 'seulement régler sa visibilité et sa disponibilité affichée.'
            );
        }
        if ($scope === 'occurrence' && $has('visibilite')) {
            throw new \RuntimeException('La visibilité d\'une réunion récurrente se règle sur toute la série : Google ignore '
                . 'une visibilité moins stricte posée sur une seule occurrence. Repasse avec `toute_la_serie: true`.');
        }

        $tzName  = self::argTimeZone($args);
        $payload = [];
        if ($has('titre')) {
            $payload['summary'] = mb_substr(trim((string)$args['titre']), 0, 1024);
        }
        if ($has('debut') || $has('fin') || $wantAllDay) {
            $payload += self::updatedBounds(
                $has('debut') ? trim((string)$args['debut']) : '',
                $has('fin') ? trim((string)$args['fin']) : '',
                $wantAllDay,
                $current,
                $tzName,
                $scope === 'serie'
            );
        }
        if ($has('lieu')) {
            $payload['location'] = mb_substr(trim((string)$args['lieu']), 0, 1000);
        }
        if ($has('description')) {
            $payload['description'] = mb_substr(trim((string)$args['description']), 0, 8000);
        }

        // Invités : état actuel (entrées gardées telles quelles : les réponses
        // restent), puis retraits et ajouts.
        $list = [];
        foreach ((array)($current['attendees'] ?? []) as $a) {
            $addr = is_array($a) ? trim((string)($a['email'] ?? '')) : '';
            if ($addr !== '') {
                $list[strtolower($addr)] = $a;
            }
        }
        $ajoutes = [];
        $retires = [];
        $changed = false;
        foreach (self::parseAddresses($args['retirer_invites'] ?? null) as $addr) {
            $k = strtolower($addr);
            if (!isset($list[$k])) {
                throw new \RuntimeException('« ' . $addr . ' » ne fait pas partie des invités de cet évènement — lis-le '
                    . 'avec lire_evenement_agenda pour voir qui est invité.');
            }
            if (!empty($list[$k]['organizer']) || !empty($list[$k]['self'])) {
                throw new \RuntimeException('« ' . $addr . ' » organise cette réunion : on ne la retire pas de ses invités.');
            }
            $retires[] = (string)$list[$k]['email'];
            unset($list[$k]);
            $changed = true;
        }
        $moi = strtolower(trim($accountEmail));
        $org = strtolower(trim((string)($organizerNode['email'] ?? '')));
        foreach ([
            'required' => self::parseAddresses($args['ajouter_invites'] ?? null),
            'optional' => self::parseAddresses($args['ajouter_invites_optionnels'] ?? null),
        ] as $type => $addrs) {
            foreach ($addrs as $addr) {
                $k = strtolower($addr);
                if ($k === $moi || $k === $org) {
                    continue; // l'organisateur ne s'invite pas lui-même
                }
                $optional = $type === 'optional';
                if (isset($list[$k])) {
                    if (!empty($list[$k]['resource']) || !empty($list[$k]['optional']) === $optional) {
                        continue; // déjà invité au même titre
                    }
                    $list[$k]['optional'] = $optional;
                    $changed = true;
                    continue;
                }
                $list[$k] = ['email' => $addr] + ($optional ? ['optional' => true] : []);
                $ajoutes[] = $addr;
                $changed = true;
            }
        }
        $guests = self::attendeeEmails(array_values($list));
        if ($changed) {
            if (count($guests) > self::MAX_ATTENDEES) {
                throw new \RuntimeException('Trop d\'invités (' . count($guests) . ') : ' . self::MAX_ATTENDEES . ' au plus par évènement.');
            }
            $payload['attendees'] = array_values($list);
        }

        $payload += self::optionsPayload($args);

        if ($payload === []) {
            throw new \RuntimeException('Aucune modification demandée : précise ce qui change (titre, horaire, lieu, '
                . 'description, invités, visibilité, disponibilité). Un invité déjà présent au même titre n\'est pas un changement.');
        }

        // Le changement concerne-t-il les invités ? Une liste modifiée,
        // toujours (un retiré apprend l'annulation) ; sinon ce qu'ils voient.
        $visible = array_intersect(array_keys($payload), ['summary', 'start', 'end', 'location', 'description']);
        $touche  = $canModify && ($changed || ($guests !== [] && $visible !== []));

        return [
            'payload'        => $payload,
            'previent'       => $touche && self::wantsInvitations($args),
            'touche_invites' => $touche,
            'organisateur'   => $organizer,
            'invites'        => $guests,
            'ajoutes'        => $ajoutes,
            'retires'        => $retires,
        ];
    }

    /**
     * Nouvel horaire d'un évènement existant (cf. buildUpdatePayload). Forme
     * PATCH : le champ de l'autre forme (date / dateTime) est vidé par null,
     * sinon Google garderait les deux en passant d'une journée entière à un
     * horaire (ou l'inverse).
     *
     * @return array{start: array<string,mixed>, end: array<string,mixed>}
     */
    private static function updatedBounds(string $debut, string $fin, bool $wantAllDay, array $current, string $tzName, bool $series): array
    {
        $curAllDay = isset($current['start']['date']);
        $curStart  = self::boundaryIn($current['start'] ?? null, $tzName);
        $curEnd    = self::boundaryIn($current['end'] ?? null, $tzName);
        if ($curStart === null || $curEnd === null) {
            throw new \RuntimeException('Horaire actuel de l\'évènement illisible : donne `debut` ET `fin`.');
        }
        // Dernier jour réellement occupé (la fin d'une journée entière est exclusive).
        $curLast = $curAllDay ? $curEnd->modify('-1 day') : $curEnd;

        // Heure seule : le jour reste celui de l'évènement.
        $debut = self::withDayOf($debut, $curStart);
        $fin   = self::withDayOf($fin, $curLast);
        $debutDay = $debut !== '' && self::isDayOnly($debut);
        $finDay   = $fin !== '' && self::isDayOnly($fin);

        if ($series) {
            $toAllDay = $wantAllDay || ($curAllDay && ($debut === '' || $debutDay) && ($fin === '' || $finDay));
            if ($toAllDay !== $curAllDay) {
                throw new \RuntimeException('Une série ne passe pas d\'horaire en journée entière (ni l\'inverse) ici : '
                    . 'cela se fait dans Google Agenda.');
            }
            if (($debut !== '' && substr($debut, 0, 10) !== $curStart->format('Y-m-d'))
                || ($fin !== '' && substr($fin, 0, 10) !== $curLast->format('Y-m-d'))) {
                throw new \RuntimeException('Pour toute la série, garde le jour de la première occurrence ('
                    . $curStart->format('Y-m-d') . ') : donne seulement l\'heure (`debut` au format HH:MM). '
                    . 'Déplacer les jours d\'une série se fait dans Google Agenda.');
            }
        }
        $zone = $series ? ['timeZone' => $tzName] : [];

        if ($wantAllDay || ($curAllDay && ($debut === '' || $debutDay) && ($fin === '' || $finDay))) {
            if (($debut !== '' && !$debutDay) || ($fin !== '' && !$finDay)) {
                throw new \RuntimeException('Un évènement « journée entière » attend des dates sans heure (AAAA-MM-JJ).');
            }
            $start = $debut !== ''
                ? self::parseDateInput($debut, false, $tzName)
                : self::parseDateInput($curStart->format('Y-m-d'), false, $tzName);
            if ($fin !== '') {
                $last = self::parseDateInput($fin, false, $tzName);
            } elseif ($debut !== '') {
                // Début déplacé : même nombre de jours.
                $days = max(0, (int)$curStart->setTime(0, 0)->diff($curLast->setTime(0, 0))->days);
                $last = $start?->modify('+' . $days . ' days');
            } else {
                $last = self::parseDateInput($curLast->format('Y-m-d'), false, $tzName);
            }
            if ($start === null || $last === null || $last < $start) {
                throw new \RuntimeException('La date de fin doit être postérieure ou égale à la date de début.');
            }
            return [
                'start' => ['date' => $start->format('Y-m-d'), 'dateTime' => null] + $zone,
                'end'   => ['date' => $last->modify('+1 day')->format('Y-m-d'), 'dateTime' => null] + $zone,
            ];
        }

        // Horodaté. Durée gardée quand seul le début bouge (une heure pour un
        // évènement qui était en journée entière).
        $duration = $curAllDay ? 3600 : max(300, $curEnd->getTimestamp() - $curStart->getTimestamp());
        if ($debut === '') {
            $start = $curStart;
        } elseif ($debutDay) {
            if ($curAllDay) {
                throw new \RuntimeException('Heure de début manquante (format AAAA-MM-JJ HH:MM).');
            }
            // Une date seule déplace le JOUR, l'heure reste.
            $start = self::parseDateInput($debut . ' ' . $curStart->format('H:i'), false, $tzName);
        } else {
            $start = self::parseDateInput($debut, false, $tzName);
        }
        if ($fin === '') {
            $end = $start?->modify('+' . $duration . ' seconds');
        } elseif ($finDay) {
            throw new \RuntimeException('Heure de fin manquante (format AAAA-MM-JJ HH:MM).');
        } else {
            $end = self::parseDateInput($fin, false, $tzName);
        }
        if ($start === null || $end === null || $end <= $start) {
            throw new \RuntimeException('L\'heure de fin doit être postérieure à l\'heure de début.');
        }
        return [
            'start' => ['dateTime' => $start->format(DATE_RFC3339), 'timeZone' => $tzName, 'date' => null],
            'end'   => ['dateTime' => $end->format(DATE_RFC3339), 'timeZone' => $tzName, 'date' => null],
        ];
    }

    /** Borne Google (date ou dateTime) dans le fuseau de l'utilisateur, ou null. */
    private static function boundaryIn(mixed $node, string $tzName): ?\DateTimeImmutable
    {
        if (!is_array($node)) {
            return null;
        }
        try {
            if (isset($node['date'])) {
                return self::parseDateInput((string)$node['date'], false, $tzName);
            }
            if (isset($node['dateTime'])) {
                return (new \DateTimeImmutable((string)$node['dateTime']))->setTimezone(new \DateTimeZone($tzName));
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    /** « HH:MM » → « AAAA-MM-JJ HH:MM » au jour de `$ref` ; toute autre saisie inchangée. */
    private static function withDayOf(string $value, \DateTimeImmutable $ref): string
    {
        if (preg_match('/^(\d{1,2})[:h](\d{2})$/i', $value, $m) === 1 && (int)$m[1] < 24 && (int)$m[2] < 60) {
            return $ref->format('Y-m-d') . ' ' . sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        }
        return $value;
    }

    /**
     * Adresses email d'une liste d'invités (texte du modèle, liste JSON),
     * séparées par virgules / points-virgules / lignes ; « Nom <adresse> »
     * réduit à l'adresse ; doublons retirés (sans casse). Une adresse invalide
     * LÈVE : l'ignorer inviterait moins de monde que demandé, sans le dire.
     * Source unique, partagée avec le calendrier Outlook.
     *
     * @return list<string>
     */
    public static function parseAddresses(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_scalar($raw) && !is_bool($raw)) {
            $parts = preg_split('/[,;\r\n]+/', (string)$raw) ?: [];
        } else {
            return [];
        }
        $out = [];
        foreach ($parts as $p) {
            if (is_array($p)) {
                $p = $p['email'] ?? ($p['adresse'] ?? ($p['address'] ?? ''));
            }
            if (!is_scalar($p)) {
                continue;
            }
            $p = trim((string)$p);
            if ($p === '') {
                continue;
            }
            if (preg_match('/<([^<>]+)>\s*$/', $p, $m) === 1) {
                $p = trim($m[1]);
            }
            if (mb_strlen($p) > 254 || filter_var($p, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Adresse d\'invité invalide : « ' . mb_substr($p, 0, 80) . ' ». Donne '
                    . 'l\'adresse email complète de chaque invité ; si tu ne la connais pas, demande-la à '
                    . 'l\'utilisateur plutôt que de la deviner.');
            }
            $out[strtolower($p)] ??= $p;
        }
        return array_values($out);
    }

    /**
     * Invités d'une création, forme Google. Un invité cité deux fois est
     * obligatoire ; l'organisateur ne s'invite pas lui-même.
     *
     * @param list<string> $required
     * @param list<string> $optional
     * @return list<array{email: string, optional?: bool}>
     */
    public static function attendeeList(array $required, array $optional, string $accountEmail = ''): array
    {
        $moi  = strtolower(trim($accountEmail));
        $seen = [];
        $out  = [];
        foreach (['required' => $required, 'optional' => $optional] as $type => $addrs) {
            foreach ($addrs as $addr) {
                $k = strtolower($addr);
                if ($k === $moi || isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $out[]    = ['email' => $addr] + ($type === 'optional' ? ['optional' => true] : []);
            }
        }
        return $out;
    }

    /**
     * Adresses des INVITÉS d'une liste Google : ni le compte lui-même (`self`,
     * l'organisateur d'une réunion qu'il crée), ni une ressource (salle).
     *
     * @return list<string>
     */
    public static function attendeeEmails(array $attendees): array
    {
        $out = [];
        foreach ($attendees as $a) {
            if (!is_array($a) || !empty($a['self']) || !empty($a['resource'])) {
                continue;
            }
            $email = trim((string)($a['email'] ?? ''));
            if ($email !== '') {
                $out[] = $email;
            }
        }
        return $out;
    }

    /**
     * Les invités recevront-ils l'email d'invitation ? Oui sauf refus explicite
     * (`envoyer_invitations: false`, « non »…) : une personne qu'on ajoute à
     * une réunion s'attend à la recevoir (piège 1).
     */
    public static function wantsInvitations(array $args): bool
    {
        $v = $args['envoyer_invitations'] ?? null;
        if ($v === null || $v === '') {
            return true;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (bool)$v;
        }
        if (!is_string($v)) {
            return true;
        }
        return !in_array(self::fold($v), ['false', 'non', 'no', 'faux', '0', 'off', 'aucune', 'jamais'], true);
    }

    /**
     * Visibilité et disponibilité demandées → propriétés Google (pièges 3 et 4).
     * Un mot inconnu LÈVE (avant la carte) : l'ignorer créerait une réunion
     * visible là où l'utilisateur la voulait privée.
     *
     * @return array<string,string>
     */
    public static function optionsPayload(array $args): array
    {
        $out = [];
        foreach ([
            ['visibilite', self::VISIBILITY, 'visibility'],
            ['disponibilite', self::AVAILABILITY, 'transparency'],
        ] as [$key, $map, $property]) {
            $word = self::settingInput($key, $args[$key] ?? null, $map);
            if ($word !== '') {
                $out[$property] = $map[$word];
            }
        }
        return $out;
    }

    /** Mot de l'outil d'un réglage (alias compris), '' si absent. Throw si inconnu. */
    private static function settingInput(string $key, mixed $raw, array $map): string
    {
        $word = is_scalar($raw) && !is_bool($raw) ? self::wordKey((string)$raw) : '';
        if ($word === '') {
            return '';
        }
        $word = self::SETTING_ALIASES[$key][$word] ?? $word;
        if (!isset($map[$word])) {
            throw new \RuntimeException('Valeur de `' . $key . '` inconnue : « ' . mb_substr((string)$raw, 0, 40)
                . ' » (attendu : ' . implode(', ', array_keys($map)) . ').');
        }
        return $word;
    }

    /** Valeur Google lue → mot de l'outil (`confidential` = privée). */
    public static function settingWord(array $map, string $value, string $default): string
    {
        if ($value === 'confidential') {
            return 'privee';
        }
        $word = array_search($value, $map, true);
        return is_string($word) ? $word : $default;
    }

    /** Type de récurrence demandé (alias compris), '' = aucune. Throw si inconnu. */
    public static function recurrenceType(mixed $raw): string
    {
        $type = is_scalar($raw) && !is_bool($raw) ? self::wordKey((string)$raw) : '';
        if ($type === '' || in_array($type, ['aucune', 'aucun', 'non', 'none', 'jamais', 'unique', 'ponctuelle'], true)) {
            return '';
        }
        $type = self::RECURRENCE_ALIASES[$type] ?? $type;
        if (!in_array($type, self::RECURRENCES, true)) {
            throw new \RuntimeException('Récurrence inconnue : « ' . mb_substr((string)$raw, 0, 40) . ' » (attendu : '
                . implode(', ', self::RECURRENCES) . '). Une récurrence plus fine se règle dans Google Agenda.');
        }
        return $type;
    }

    /**
     * Règle d'une récurrence demandée, ou null. Le début DOIT en être une
     * occurrence (piège 2) : un mardi pour « chaque lundi », c'est une erreur
     * renvoyée au modèle, pas une série au comportement indéfini.
     *
     * @return array{type:string,interval:int,days:list<int>,monthly:string,nth:int,until:string,count:int,exceptions:list<string>}|null
     */
    public static function recurrenceSpec(array $args, \DateTimeImmutable $start): ?array
    {
        $text = static fn(string $k): string => is_scalar($args[$k] ?? null) && !is_bool($args[$k])
            ? trim((string)$args[$k]) : '';
        $type = self::recurrenceType($args['recurrence'] ?? null);
        if ($type === '') {
            // Des précisions de répétition sans répétition : ne pas les ignorer
            // en silence (le modèle a oublié `recurrence`).
            foreach (['recurrence_jours', 'recurrence_fin', 'recurrence_mensuelle', 'recurrence_exceptions'] as $k) {
                if ($text($k) !== '' || (is_array($args[$k] ?? null) && $args[$k] !== [])) {
                    throw new \RuntimeException('`' . $k . '` n\'a de sens que pour une réunion récurrente : précise '
                        . '`recurrence` (' . implode(', ', self::RECURRENCES) . ').');
                }
            }
            return null;
        }
        $interval = is_numeric($args['recurrence_intervalle'] ?? null)
            ? max(1, min(99, (int)$args['recurrence_intervalle']))
            : 1;
        $dow     = (int)$start->format('N');
        $dayName = self::DAYS_FR[$dow];
        $days    = [];
        $monthly = '';
        $nth     = 0;

        if ($type === 'jours_ouvres') {
            if ($dow > 5) {
                throw new \RuntimeException('Le début tombe un ' . $dayName . ' : une réunion « jours ouvrés » commence un '
                    . 'jour de semaine (du lundi au vendredi).');
            }
            $days     = [1, 2, 3, 4, 5];
            $interval = 1;
        } elseif ($type === 'hebdomadaire') {
            $days = self::parseDays($args['recurrence_jours'] ?? null);
            if ($days === []) {
                $days = [$dow];
            }
            if (!in_array($dow, $days, true)) {
                throw new \RuntimeException('Le début (' . $dayName . ') doit tomber un des jours de la '
                    . 'récurrence : choisis un `debut` qui est le premier jour voulu.');
            }
        } elseif ($type === 'mensuelle') {
            $monthly = self::monthlyMode($args['recurrence_mensuelle'] ?? null);
            $d   = (int)$start->format('j');
            $dim = (int)$start->format('t');
            if ($monthly === 'meme_jour_semaine') {
                $nth = intdiv($d - 1, 7) + 1;
                if ($nth === 5) {
                    throw new \RuntimeException('Le début est le 5e ' . $dayName . ' du mois, qui n\'existe pas tous les '
                        . 'mois : choisis `recurrence_mensuelle: dernier_jour_semaine` (le dernier ' . $dayName
                        . ' du mois) ou `meme_date`.');
                }
            } elseif ($monthly === 'dernier_jour_semaine') {
                if ($d + 7 <= $dim) {
                    throw new \RuntimeException('Le début (' . $start->format('Y-m-d') . ') n\'est pas le dernier ' . $dayName
                        . ' de son mois : choisis comme `debut` le dernier ' . $dayName . ' du mois voulu.');
                }
                $nth = -1;
            }
        }

        $until = $text('recurrence_fin');
        $count = 0;
        if ($until !== '') {
            if (!self::isDayOnly($until)) {
                throw new \RuntimeException('`recurrence_fin` attend une date AAAA-MM-JJ.');
            }
            self::parseDateInput($until, false);
            if ($until < $start->format('Y-m-d')) {
                throw new \RuntimeException('La fin de la récurrence précède le début de l\'évènement.');
            }
        } elseif (is_numeric($args['recurrence_occurrences'] ?? null) && (int)$args['recurrence_occurrences'] > 0) {
            $count = min(self::MAX_OCCURRENCES, (int)$args['recurrence_occurrences']);
        }

        $spec = [
            'type' => $type, 'interval' => $interval, 'days' => $days, 'monthly' => $monthly, 'nth' => $nth,
            'until' => $until, 'count' => $count, 'exceptions' => [],
        ];

        // Dates sautées (« sauf le 25 décembre ») : chacune doit être une
        // occurrence de la série, sinon l'utilisateur croirait l'avoir retirée.
        $raw = $args['recurrence_exceptions'] ?? null;
        $parts = is_array($raw) ? $raw : (is_scalar($raw) && !is_bool($raw) ? (preg_split('/[\s,;]+/', (string)$raw) ?: []) : []);
        $exceptions = [];
        foreach ($parts as $p) {
            $p = is_scalar($p) ? trim((string)$p) : '';
            if ($p === '') {
                continue;
            }
            if (!self::isDayOnly($p)) {
                throw new \RuntimeException('`recurrence_exceptions` attend des dates AAAA-MM-JJ, séparées par des virgules.');
            }
            self::parseDateInput($p, false);
            if (($until !== '' && $p > $until) || !self::occursOn($spec, $start, $p)) {
                throw new \RuntimeException('Le ' . $p . ' n\'est pas une date de cette série : une exception doit tomber '
                    . 'un jour où la réunion a lieu.');
            }
            $exceptions[$p] = true;
        }
        if (count($exceptions) > self::MAX_EXCEPTIONS) {
            throw new \RuntimeException('Trop de dates sautées (' . count($exceptions) . ') : ' . self::MAX_EXCEPTIONS . ' au plus.');
        }
        ksort($exceptions);
        $spec['exceptions'] = array_keys($exceptions);
        return $spec;
    }

    /** Mode de récurrence mensuelle (alias compris) ; défaut : le même quantième. Throw si inconnu. */
    private static function monthlyMode(mixed $raw): string
    {
        $mode = is_scalar($raw) && !is_bool($raw) ? self::wordKey((string)$raw) : '';
        if ($mode === '') {
            return 'meme_date';
        }
        $mode = self::MONTHLY_ALIASES[$mode] ?? $mode;
        if (!in_array($mode, self::MONTHLY_MODES, true)) {
            throw new \RuntimeException('`recurrence_mensuelle` inconnue : « ' . mb_substr((string)$raw, 0, 40) . ' » (attendu : '
                . implode(', ', self::MONTHLY_MODES) . ').');
        }
        return $mode;
    }

    /**
     * La date AAAA-MM-JJ est-elle une occurrence de la règle ? (sans tenir
     * compte de COUNT). Calcul en jours civils — un changement d'heure ne
     * doit pas faire perdre un jour.
     */
    public static function occursOn(array $spec, \DateTimeImmutable $start, string $date): bool
    {
        $utc = new \DateTimeZone('UTC');
        $s = \DateTimeImmutable::createFromFormat('!Y-m-d', $start->format('Y-m-d'), $utc);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $utc);
        if ($s === false || $d === false || $d < $s) {
            return false;
        }
        $interval = max(1, (int)($spec['interval'] ?? 1));
        $dow      = (int)$d->format('N');
        $months   = ((int)$d->format('Y') * 12 + (int)$d->format('n')) - ((int)$s->format('Y') * 12 + (int)$s->format('n'));
        switch ($spec['type'] ?? '') {
            case 'quotidienne':
                return (int)$s->diff($d)->days % $interval === 0;
            case 'jours_ouvres':
                return $dow <= 5;
            case 'hebdomadaire':
                if (!in_array($dow, (array)($spec['days'] ?? []), true)) {
                    return false;
                }
                $weekS = $s->modify('-' . ((int)$s->format('N') - 1) . ' days');
                $weekD = $d->modify('-' . ($dow - 1) . ' days');
                return intdiv((int)$weekS->diff($weekD)->days, 7) % $interval === 0;
            case 'mensuelle':
                if ($months % $interval !== 0) {
                    return false;
                }
                $j = (int)$d->format('j');
                return match ($spec['monthly'] ?? 'meme_date') {
                    'meme_jour_semaine'    => $dow === (int)$s->format('N') && intdiv($j - 1, 7) + 1 === (int)$spec['nth'],
                    'dernier_jour_semaine' => $dow === (int)$s->format('N') && $j + 7 > (int)$d->format('t'),
                    default                => $j === (int)$s->format('j'),
                };
            case 'annuelle':
                return ((int)$d->format('Y') - (int)$s->format('Y')) % $interval === 0
                    && $d->format('m-d') === $s->format('m-d');
        }
        return false;
    }

    /**
     * Règle → lignes RFC 5545 de `recurrence[]` (piège 2). `UNTIL` : une date
     * pour une journée entière, sinon la fin du dernier jour (fuseau de
     * l'utilisateur) en UTC. `EXDATE` : même forme que le début (date, ou
     * heure locale du début avec son fuseau).
     *
     * @return list<string>
     */
    public static function rruleLines(array $spec, \DateTimeImmutable $start, bool $allDay, string $tzName): array
    {
        $parts = ['FREQ=' . match ($spec['type']) {
            'quotidienne'  => 'DAILY',
            'mensuelle'    => 'MONTHLY',
            'annuelle'     => 'YEARLY',
            default        => 'WEEKLY',
        }];
        if ((int)$spec['interval'] > 1) {
            $parts[] = 'INTERVAL=' . (int)$spec['interval'];
        }
        if (in_array($spec['type'], ['jours_ouvres', 'hebdomadaire'], true)) {
            $parts[] = 'BYDAY=' . implode(',', array_map(static fn(int $d): string => self::RRULE_DAYS[$d], $spec['days']));
        } elseif ($spec['type'] === 'mensuelle') {
            $parts[] = $spec['monthly'] === 'meme_date'
                ? 'BYMONTHDAY=' . (int)$start->format('j')
                : 'BYDAY=' . (int)$spec['nth'] . self::RRULE_DAYS[(int)$start->format('N')];
        }
        if ($spec['until'] !== '') {
            $parts[] = 'UNTIL=' . ($allDay
                ? str_replace('-', '', $spec['until'])
                : (new \DateTimeImmutable($spec['until'] . ' 23:59:59', new \DateTimeZone($tzName)))
                    ->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
        } elseif ((int)$spec['count'] > 0) {
            $parts[] = 'COUNT=' . (int)$spec['count'];
        }
        $lines = ['RRULE:' . implode(';', $parts)];
        if ($spec['exceptions'] !== []) {
            $dates = array_map(static fn(string $d): string => str_replace('-', '', $d), $spec['exceptions']);
            $lines[] = $allDay
                ? 'EXDATE;VALUE=DATE:' . implode(',', $dates)
                : 'EXDATE;TZID=' . $tzName . ':' . implode(',', array_map(
                    static fn(string $d): string => $d . 'T' . $start->format('His'),
                    $dates
                ));
        }
        return $lines;
    }

    /**
     * Jours d'une récurrence hebdomadaire (« lundi, jeudi ») → jours ISO triés.
     *
     * @return list<int>
     */
    public static function parseDays(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_scalar($raw) && !is_bool($raw)) {
            $parts = preg_split('/[\s,;]+/', (string)$raw) ?: [];
        } else {
            return [];
        }
        $out = [];
        foreach ($parts as $p) {
            $word = is_scalar($p) ? self::fold((string)$p) : '';
            if ($word === '' || $word === 'et' || $word === 'and') {
                continue;
            }
            if (!isset(self::DAY_NAMES[$word])) {
                throw new \RuntimeException('Jour de récurrence inconnu : « ' . mb_substr((string)$p, 0, 30) . ' » '
                    . '(attendu : lundi, mardi, mercredi, jeudi, vendredi, samedi, dimanche).');
            }
            $out[self::DAY_NAMES[$word]] = true;
        }
        $days = array_keys($out);
        sort($days);
        return $days;
    }

    /**
     * L'action concerne-t-elle des invités (invitation, mise à jour, ou simple
     * ajout à leur agenda) ? Une tâche « sans validation » qui n'a pas le
     * droit d'envoyer des emails ne l'exécute pas seule. Pour une
     * modification, la réponse vient du serveur (`previent_invites`, posé par
     * le handler après lecture de l'évènement), jamais du modèle.
     */
    public static function notifiesAttendees(string $tool, array $args): bool
    {
        if ($tool === self::TOOL_UPDATE) {
            return !empty($args['previent_invites']);
        }
        if ($tool !== self::TOOL_CREATE) {
            return false;
        }
        try {
            return self::parseAddresses($args['invites'] ?? ($args['participants'] ?? null)) !== []
                || self::parseAddresses($args['invites_optionnels'] ?? null) !== [];
        } catch (\RuntimeException) {
            return true;
        }
    }

    /** Identifiant d'évènement Google (occurrence comprise : « id_20261120T090000Z »). Throw si invalide. */
    public static function assertEventId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[A-Za-z0-9_@.-]{5,1024}$/', $id) !== 1) {
            throw new \RuntimeException('Identifiant d\'évènement invalide — utilise rechercher_agenda d\'abord.');
        }
        return $id;
    }

    /** « AAAA-MM-JJ » sans heure ? */
    private static function isDayOnly(string $s): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
    }

    /** Booléen tel que les modèles l'écrivent (true, "true", 1, "oui"). */
    private static function truthy(mixed $v): bool
    {
        if (is_string($v) && in_array(self::fold($v), ['oui', 'vrai'], true)) {
            return true;
        }
        return is_scalar($v) && filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /** Minuscules sans accents, blancs réduits. */
    private static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'œ' => 'oe',
        ]);
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    /** Mot d'un réglage : « Par défaut », « jours-ouvrés » → par_defaut, jours_ouvres. */
    private static function wordKey(string $s): string
    {
        return trim((string)preg_replace('/[\s\'’-]+/u', '_', self::fold($s)), '_');
    }

    /**
     * Borne start/end d'un évènement Google → texte FR lisible :
     * « vendredi 07/08/2026 09:00 » (dateTime, affiché dans `$timeZone` s'il
     * est valide, sinon dans le fuseau porté par l'horodatage) ou
     * « vendredi 07/08/2026 (journée entière) » (date).
     */
    public static function formatEventBoundary(mixed $node, string $timeZone = ''): string
    {
        if (!is_array($node)) {
            return '';
        }
        try {
            if (isset($node['dateTime'])) {
                $d  = new \DateTimeImmutable((string)$node['dateTime']);
                $tz = self::validTimeZone($timeZone);
                if ($tz !== '') {
                    $d = $d->setTimezone(new \DateTimeZone($tz));
                }
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
     * Aperçu HUMAIN d'une création ou d'une modification pour la carte
     * d'approbation (mêmes clés que UserGmailService::actionPreview + icone).
     *
     * `$ctx` : pour une modification, ce que le handler a lu de l'évènement
     * (`inspectUpdate`) — sans lui, la carte annoncerait un identifiant au
     * lieu de nommer la réunion, et ne saurait pas dire qui sera prévenu.
     *
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string,icone:string}
     */
    public static function actionPreview(string $tool, array $args, array $ctx = []): array
    {
        $desc   = trim((string)($args['description'] ?? ''));
        $apercu = $desc === '' ? null : (mb_strlen($desc) > 280 ? mb_substr($desc, 0, 280) . '…' : $desc);
        $quand  = self::whenLabel($args);

        if ($tool === self::TOOL_UPDATE) {
            return [
                'action'  => t('Modifier un évènement Google Agenda'),
                'fichier' => (trim((string)($ctx['titre'] ?? '')) ?: t('(sans titre)'))
                    . (trim((string)($ctx['quand'] ?? '')) !== '' ? ' — ' . trim((string)$ctx['quand']) : ''),
                'url'     => preg_match('#^https://#i', (string)($ctx['lien'] ?? '')) === 1 ? (string)$ctx['lien'] : null,
                'impacts' => self::updateImpacts($args, $ctx, $quand),
                'apercu'  => $apercu,
                'icone'   => '/assets/img/google-calendar.svg',
            ];
        }

        $titre  = trim((string)($args['titre'] ?? '')) ?: t('(sans titre)');
        $agenda = trim((string)($args['agenda'] ?? ''));
        $lieu   = trim((string)($args['lieu'] ?? ''));
        try {
            $attendees = self::attendeeList(
                self::parseAddresses($args['invites'] ?? ($args['participants'] ?? null)),
                self::parseAddresses($args['invites_optionnels'] ?? null)
            );
        } catch (\RuntimeException) {
            $attendees = []; // déjà refusé par le handler : la carte n'existe pas
        }
        $names = array_map(
            static fn(array $a): string => $a['email'] . (!empty($a['optional']) ? ' ' . t('(facultatif)') : ''),
            $attendees
        );

        $impacts = [
            $agenda !== '' && strcasecmp($agenda, 'primary') !== 0
                ? t('Un évènement sera créé dans votre agenda Google (agenda : {agenda}).', ['agenda' => $agenda])
                : t('Un évènement sera créé dans votre agenda Google principal.'),
        ];
        if ($names !== [] && self::wantsInvitations($args)) {
            $impacts[] = t('Google Agenda enverra AUSSITÔT une invitation par email, en votre nom, à : {invites}.', [
                'invites' => implode(', ', $names),
            ]);
            $impacts[] = t('Une invitation envoyée ne se rattrape pas : vérifiez les adresses avant d\'approuver.');
        } elseif ($names !== []) {
            $impacts[] = t('Invités ajoutés SANS email d\'invitation : {invites}. La réunion apparaîtra dans leur agenda Google ; une adresse hors Google ne sera prévenue de rien.', [
                'invites' => implode(', ', $names),
            ]);
        } else {
            $impacts[] = t('AUCUNE invitation ne sera envoyée : l\'évènement est créé sans participant.');
        }
        $recurrence = self::recurrenceLabel($args);
        if ($recurrence !== '') {
            $impacts[] = t('Évènement récurrent : {recurrence}.', ['recurrence' => $recurrence]);
        }
        $impacts = array_merge($impacts, self::settingImpacts($args));
        $impacts[] = t('Vous pourrez le modifier ou le supprimer à tout moment dans Google Agenda.');

        return [
            'action'  => t('Créer un évènement Google Agenda'),
            'fichier' => $titre . ($quand !== '' ? ' — ' . $quand : '') . ($lieu !== '' ? ' — ' . $lieu : ''),
            'url'     => null,
            'impacts' => $impacts,
            'apercu'  => $apercu,
            'icone'   => '/assets/img/google-calendar.svg',
        ];
    }

    /** « 2026-11-20 14:00 → 2026-11-20 15:00 (Europe/Paris) », tel que demandé. */
    private static function whenLabel(array $args): string
    {
        $quand = trim((string)($args['debut'] ?? ''));
        $fin   = trim((string)($args['fin'] ?? ''));
        if ($fin !== '') {
            $quand = ($quand !== '' ? $quand . ' ' : '') . '→ ' . $fin;
        }
        if (self::truthy($args['journee_entiere'] ?? null)) {
            return trim($quand . ' ' . t('(journée entière)'));
        }
        if ($quand !== '' && self::validTimeZone((string)($args['fuseau'] ?? '')) !== '') {
            // Le fuseau des heures affichées, nommé : la carte ne doit laisser
            // aucun doute sur l'heure qui sera réellement bloquée.
            $quand .= ' (' . (string)$args['fuseau'] . ')';
        }
        return $quand;
    }

    /** Lignes de la carte pour la visibilité et la disponibilité demandées. */
    private static function settingImpacts(array $args): array
    {
        $out = [];
        try {
            $visibilite    = self::settingInput('visibilite', $args['visibilite'] ?? null, self::VISIBILITY);
            $disponibilite = self::settingInput('disponibilite', $args['disponibilite'] ?? null, self::AVAILABILITY);
        } catch (\RuntimeException) {
            return []; // déjà refusé par le handler
        }
        $out[] = match ($visibilite) {
            'privee'     => t('Visibilité privée : qui voit votre agenda n\'y verra qu\'un créneau occupé, sans le détail.'),
            'publique'   => t('Visibilité publique : le détail est visible de toute personne qui a accès à votre agenda.'),
            'par_defaut' => t('Visibilité : celle de votre agenda (par défaut).'),
            default      => '',
        };
        $out[] = match ($disponibilite) {
            'disponible' => t('Vous apparaîtrez disponible pendant l\'évènement : le créneau ne sera pas bloqué.'),
            'occupe'     => t('Vous apparaîtrez occupé pendant l\'évènement.'),
            default      => '',
        };
        return array_values(array_filter($out, static fn(string $l): bool => $l !== ''));
    }

    /** Récurrence demandée, en clair pour la carte ('' = aucune). */
    public static function recurrenceLabel(array $args): string
    {
        try {
            $start = self::parseDateInput(trim((string)($args['debut'] ?? '')), false, self::argTimeZone($args));
            $spec  = $start !== null ? self::recurrenceSpec($args, $start) : null;
        } catch (\RuntimeException) {
            return '';
        }
        if ($spec === null || $start === null) {
            return '';
        }
        $n       = (int)$spec['interval'];
        $dayName = static fn(int $d): string => t(self::DAYS_FR[$d]);
        $jour    = $dayName((int)$start->format('N'));
        $label = match ($spec['type']) {
            'quotidienne'  => $n > 1 ? t('tous les {n} jours', ['n' => $n]) : t('chaque jour'),
            'jours_ouvres' => t('chaque jour ouvré'),
            'hebdomadaire' => ($n > 1 ? t('toutes les {n} semaines', ['n' => $n]) : t('chaque semaine'))
                . ' (' . implode(', ', array_map($dayName, $spec['days'])) . ')',
            'mensuelle'    => ($n > 1 ? t('tous les {n} mois', ['n' => $n]) : t('chaque mois')) . ', ' . match ($spec['monthly']) {
                'dernier_jour_semaine' => t('le dernier {jour} du mois', ['jour' => $jour]),
                'meme_jour_semaine'    => match ((int)$spec['nth']) {
                    1       => t('le premier {jour} du mois', ['jour' => $jour]),
                    2       => t('le deuxième {jour} du mois', ['jour' => $jour]),
                    3       => t('le troisième {jour} du mois', ['jour' => $jour]),
                    default => t('le quatrième {jour} du mois', ['jour' => $jour]),
                },
                default => t('le {n} du mois', ['n' => (int)$start->format('j')])
                    . ((int)$start->format('j') > 28 ? ' ' . t('(les mois plus courts sont sautés)') : ''),
            },
            default        => $n > 1 ? t('tous les {n} ans', ['n' => $n]) : t('chaque année'),
        };
        if ($spec['until'] !== '') {
            $label .= ' ' . t('jusqu\'au {date}', ['date' => $spec['until']]);
        } elseif ((int)$spec['count'] > 0) {
            $label .= ' ' . t('({n} fois)', ['n' => (int)$spec['count']]);
        } else {
            $label .= ' ' . t('(sans date de fin)');
        }
        if ($spec['exceptions'] !== []) {
            $label .= ', ' . t('sauf le {dates}', ['dates' => implode(', ', $spec['exceptions'])]);
        }
        return $label;
    }

    /**
     * Lignes de la carte d'une modification : ce qui change, puis qui sera prévenu.
     *
     * @return list<string>
     */
    private static function updateImpacts(array $args, array $ctx, string $quand): array
    {
        $text = static fn(string $k): string => is_scalar($args[$k] ?? null) && !is_bool($args[$k])
            ? trim((string)$args[$k]) : '';
        $impacts = [];
        if ($text('titre') !== '') {
            $impacts[] = t('Nouveau titre : « {titre} ».', ['titre' => $text('titre')]);
        }
        if ($quand !== '') {
            $impacts[] = t('Nouvel horaire : {quand}.', ['quand' => $quand]);
        }
        if ($text('lieu') !== '') {
            $impacts[] = t('Nouveau lieu : {lieu}.', ['lieu' => $text('lieu')]);
        }
        if ($text('description') !== '') {
            $impacts[] = t('La description sera remplacée.');
        }
        $ajoutes = array_values(array_filter((array)($ctx['ajoutes'] ?? []), 'is_string'));
        $retires = array_values(array_filter((array)($ctx['retires'] ?? []), 'is_string'));
        if ($ajoutes !== []) {
            $impacts[] = t('Invités ajoutés : {invites}.', ['invites' => implode(', ', $ajoutes)]);
        }
        if ($retires !== []) {
            $impacts[] = t('Invités retirés : {invites}.', ['invites' => implode(', ', $retires)]);
        }
        $impacts = array_merge($impacts, self::settingImpacts($args));

        $portee = (string)($ctx['portee'] ?? '');
        if ($portee === 'serie') {
            $impacts[] = t('C\'est une série : toutes ses occurrences seront modifiées.');
        } elseif ($portee === 'occurrence') {
            $impacts[] = t('Seule cette occurrence de la série sera modifiée.');
        }
        if (array_key_exists('organisateur', $ctx) && empty($ctx['organisateur'])) {
            $impacts[] = t('Vous n\'organisez pas cette réunion : seul votre exemplaire change.');
        }

        $invites = array_values(array_filter((array)($ctx['invites'] ?? []), 'is_string'));
        if (!empty($ctx['previent'])) {
            $impacts[] = t('Google Agenda écrira AUSSITÔT par email, en votre nom, aux invités concernés par ce changement.');
            $impacts[] = t('Un message parti ne se rattrape pas : vérifiez avant d\'approuver.');
        } elseif (!empty($ctx['touche_invites'])) {
            $impacts[] = t('Aucun email ne sera envoyé : le changement apparaîtra quand même dans l\'agenda Google des invités.');
        } elseif ($invites === []) {
            $impacts[] = t('Personne ne sera prévenu : cet évènement n\'a pas d\'invité.');
        } else {
            $impacts[] = t('Aucun email ne sera envoyé aux invités pour ce réglage.');
        }
        return $impacts;
    }
}
