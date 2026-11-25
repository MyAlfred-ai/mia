<?php

declare(strict_types=1);

/**
 * Accès au CALENDRIER OUTLOOK personnel d'un utilisateur (OAuth Microsoft par
 * utilisateur, jetons UserDriveToken provider 'outlook_calendar') : liste des
 * agendas, recherche et lecture d'évènements, création ET modification
 * d'évènements — invités, réunion Teams, récurrence, rappel, disponibilité —,
 * ces deux écritures UNIQUEMENT via la file d'approbation. Aucune suppression
 * ni annulation : cet outil n'existe pas (cf. MicrosoftCalendarOAuth pour le
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
 *  4. **Aucun équivalent de `sendUpdates=none`.** Doc Graph (Create event,
 *     relue le 2026-11-13) : « When you create an event that includes
 *     attendees, the server sends invitations to all attendees. This […] can't
 *     be configured. » Inviter quelqu'un, c'est donc lui ÉCRIRE : la carte
 *     d'approbation nomme chaque invité et dit que l'invitation part au clic.
 *     Une tâche « sans validation » qui n'a pas le droit d'envoyer des emails
 *     dépose une carte au lieu d'inviter (`notifiesAttendees`).
 *
 *  5. **Modifier = PATCH des SEULES propriétés qui changent** (doc Update
 *     event). `attendees` est la liste ENTIÈRE : on relit l'évènement et on la
 *     recompose (ajouts, retraits) — envoyer les seuls nouveaux retirerait les
 *     autres. « An event update that includes only the attendees property […]
 *     sends a meeting update to only the attendees that have changed. »
 *
 *  6. **Le corps d'une réunion en ligne porte le lien de connexion** (doc :
 *     « preserve the meeting blob […] Inadvertently removing the meeting blob
 *     from the body would disable meeting online »). La description d'une
 *     réunion Teams n'est donc pas réécrite : refus expliqué.
 *
 *  7. **Une réunion Teams ne se retire pas** : une fois `isOnlineMeeting`
 *     posé, « Outlook ignores any further changes » (ressource event). `visio`
 *     ne sait qu'ajouter.
 *
 *  8. **Une SALLE se réserve en l'INVITANT** (doc Create event, « Setting the
 *     location in an event », relue le 2026-11-17) : invitée de type
 *     `resource` + `location.locationEmailAddress`. C'est le serveur de la
 *     salle qui accepte ou refuse, selon son propre agenda — MIA ne peut donc
 *     rien promettre au clic : elle VÉRIFIE avant la carte (`getSchedule`
 *     accepte les salles, avec `Calendars.Read`) et la carte dit qu'une salle
 *     occupée refusera. Trouver une salle par son NOM passe par
 *     `/beta/me/findRooms`, qui ne demande que `User.Read` (déjà accordé) ;
 *     l'API v1 (`/places/microsoft.graph.room`) exige `Place.Read.All`, que
 *     seul un administrateur de l'annuaire peut accorder — l'ajouter aux
 *     scopes ferait refuser TOUTE la connexion du calendrier là où il ne l'a
 *     pas fait (même leçon que `ChannelMessage.Read.All` pour Teams). L'API
 *     bêta pouvant changer, une salle donnée par son ADRESSE ne passe jamais
 *     par elle ; l'annuaire en panne, le modèle demande l'adresse.
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

    /** Outils d'écriture (file d'approbation). */
    public const TOOL_CREATE = 'creer_evenement_agenda_outlook';
    public const TOOL_UPDATE = 'modifier_evenement_agenda_outlook';

    /** Outil de lecture : salles de réunion de l'organisation (et leur disponibilité). */
    public const TOOL_ROOMS = 'lister_salles_outlook';

    /** Invités par évènement, au plus (au-delà, c'est une liste de diffusion). */
    public const MAX_ATTENDEES = 50;

    /**
     * Annuaire des salles (piège 8) : API BÊTA, la seule qui n'exige pas de
     * consentement administrateur. Rend « up to the first 100 rooms ».
     */
    private const ROOMS_URL = 'https://graph.microsoft.com/beta/me/findRooms';

    /** Salles dont la disponibilité est vérifiée en une fois (`getSchedule`), au plus. */
    public const ROOMS_AVAILABILITY_MAX = 20;

    /** Salles rendues au modèle par la recherche, au plus. */
    private const ROOMS_LISTED_MAX = 40;

    public const MSG_ROOMS_UNAVAILABLE = 'Impossible de consulter la liste des salles de réunion de votre '
        . 'organisation (annuaire Microsoft injoignable, ou aucune salle déclarée pour ce compte). Demande à '
        . 'l\'utilisateur l\'adresse email de la salle : elle se réserve aussi par son adresse.';

    /** Rendu au modèle quand la salle demandée semble déjà prise à cet horaire. */
    public const ROOM_BUSY_NOTE = 'La salle semble déjà réservée à cet horaire : elle refusera sans doute la '
        . 'réunion. Dis-le à l\'utilisateur et propose une autre salle libre (lister_salles_outlook avec debut et '
        . 'fin) ou un autre horaire ; la carte reste là s\'il veut tenter quand même.';

    /** Disponibilité affichée (`showAs`) : mot de l'outil → valeur Graph. */
    public const SHOW_AS = [
        'occupe' => 'busy', 'libre' => 'free', 'provisoire' => 'tentative',
        'absent' => 'oof', 'ailleurs' => 'workingElsewhere',
    ];
    /** Importance : mot de l'outil → valeur Graph. */
    public const IMPORTANCE = ['basse' => 'low', 'normale' => 'normal', 'haute' => 'high'];
    /** Confidentialité (`sensitivity`) : mot de l'outil → valeur Graph. */
    public const SENSITIVITY = ['normale' => 'normal', 'privee' => 'private'];
    /** Récurrences proposées (les plus courantes ; le reste se règle dans Outlook). */
    public const RECURRENCES = ['quotidienne', 'jours_ouvres', 'hebdomadaire', 'mensuelle', 'annuelle'];
    /** Jour ISO (1 = lundi) → valeur Graph `dayOfWeek`. */
    private const GRAPH_DAYS = [
        1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday',
        5 => 'friday', 6 => 'saturday', 7 => 'sunday',
    ];
    /** Noms de jours acceptés dans `recurrence_jours` (minuscules, sans accents). */
    private const DAY_NAMES = [
        'lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7,
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
    ];
    /** Rappel le plus lointain accepté : quatre semaines, en minutes. */
    private const REMINDER_MAX_MIN = 40320;

    /** Rendu au modèle quand une tâche sans validation voudrait inviter sans droit d'envoi. */
    public const AUTO_HELD_NOTE = 'Cette action préviendrait des invités par email, et la tâche n\'a pas le droit '
        . 'd\'envoyer des emails sans validation : une carte a été déposée, la personne la validera. '
        . 'Dis-le dans ton compte rendu, sans affirmer que c\'est fait.';

    /** Champs lus avant une modification (cf. buildUpdatePayload). */
    private const UPDATE_SELECT = 'id,subject,start,end,isAllDay,isCancelled,isOrganizer,isOnlineMeeting,'
        . 'type,attendees,organizer,location,webLink';

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

    /** Salles de l'annuaire, lues une fois par instance (piège 8). @var list<array{nom:string,adresse:string}>|null */
    private ?array $rooms = null;

    /**
     * Salles de réunion de l'organisation (nom, adresse), par l'API bêta
     * `findRooms` (piège 8). Une connexion expirée remonte telle quelle ; toute
     * autre panne devient MSG_ROOMS_UNAVAILABLE, qui dit au modèle de demander
     * l'adresse — un 404 n'y voudrait pas dire « évènement introuvable ».
     *
     * @return list<array{nom:string,adresse:string}>
     */
    public function listRooms(): array
    {
        if ($this->rooms !== null) {
            return $this->rooms;
        }
        try {
            $data = $this->apiJson('GET', self::ROOMS_URL);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === static::MSG_RECONNECT) {
                throw $e;
            }
            error_log(self::LOG_LABEL . ' annuaire des salles indisponible : ' . $e->getMessage());
            throw new \RuntimeException(self::MSG_ROOMS_UNAVAILABLE);
        }
        return $this->rooms = self::roomsFromResponse($data);
    }

    /**
     * Salle demandée par le modèle (nom, « Nom <adresse> » ou adresse) →
     * `{nom, adresse}`. Par son NOM, elle est cherchée dans l'annuaire : une
     * seule correspondance, sinon une erreur qui NOMME les salles proches —
     * réserver la mauvaise salle ne se rattrape pas en silence. Par son
     * ADRESSE, l'annuaire n'est consulté que pour lui trouver un nom, et sa
     * panne n'empêche rien.
     *
     * @return array{nom:string,adresse:string}
     */
    public function resolveRoom(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new \RuntimeException('Salle manquante : donne son nom ou son adresse email.');
        }
        $direct = self::parseRoom($input, false);
        if ($direct !== null) {
            if ($direct['nom'] === $direct['adresse']) {
                try {
                    foreach ($this->listRooms() as $r) {
                        if (strcasecmp($r['adresse'], $direct['adresse']) === 0) {
                            return $r;
                        }
                    }
                } catch (\RuntimeException) {
                    // Annuaire injoignable : l'adresse suffit pour réserver.
                }
            }
            return $direct;
        }

        $rooms   = $this->listRooms();
        $matches = self::matchRooms($rooms, $input);
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches !== []) {
            throw new \RuntimeException('Plusieurs salles correspondent à « ' . mb_substr($input, 0, 80) . ' » : '
                . self::roomNames($matches) . '. Demande à l\'utilisateur laquelle, puis passe son nom exact.');
        }
        if ($rooms === []) {
            throw new \RuntimeException('Aucune salle de réunion n\'est déclarée dans l\'annuaire Microsoft de '
                . 'l\'organisation. Si la salle a une adresse email, demande-la à l\'utilisateur.');
        }
        throw new \RuntimeException('Aucune salle ne s\'appelle « ' . mb_substr($input, 0, 80) . ' ». Salles de '
            . 'l\'organisation : ' . self::roomNames(array_slice($rooms, 0, 15))
            . (count($rooms) > 15 ? ' (et ' . (count($rooms) - 15) . ' autres)' : '') . '.');
    }

    /**
     * Outil lister_salles_outlook : salles de l'annuaire, filtrées par nom ;
     * avec `debut` ET `fin`, leur disponibilité sur ce créneau (`getSchedule`,
     * qui accepte les salles — piège 8).
     *
     * @return array{salles: list<array>, total: int, tronque?: bool, creneau?: string, note?: string}
     */
    public function searchRooms(string $query, string $from, string $to): array
    {
        $rooms = $this->listRooms();
        $query = trim($query);
        if ($query !== '') {
            $rooms = self::matchRooms($rooms, $query);
        }
        $total = count($rooms);
        $rooms = array_slice($rooms, 0, self::ROOMS_LISTED_MAX);
        $out   = ['salles' => $rooms, 'total' => $total];
        if ($total > count($rooms)) {
            $out['tronque'] = true;
        }

        $from = trim($from);
        $to   = trim($to);
        if ($from === '' && $to === '') {
            return $out;
        }
        if ($from === '' || $to === '' || self::isDayOnly($from) || self::isDayOnly($to)) {
            throw new \RuntimeException('Pour la disponibilité des salles, donne `debut` ET `fin` au format AAAA-MM-JJ HH:MM.');
        }
        $start = UserCalendarService::parseDateInput($from, false, $this->timeZone);
        $end   = UserCalendarService::parseDateInput($to, false, $this->timeZone);
        if ($start === null || $end === null || $end <= $start) {
            throw new \RuntimeException('L\'heure de fin doit être postérieure à l\'heure de début.');
        }
        $checked = array_slice($out['salles'], 0, self::ROOMS_AVAILABILITY_MAX);
        $status  = $this->roomsAvailability(array_column($checked, 'adresse'), $start, $end);
        foreach ($out['salles'] as $i => $room) {
            $out['salles'][$i]['disponibilite'] = $status[strtolower($room['adresse'])] ?? 'non_verifiee';
        }
        // Les libres d'abord : c'est ce que l'utilisateur cherche.
        $rank = ['libre' => 0, 'inconnue' => 1, 'non_verifiee' => 2, 'occupee' => 3];
        usort($out['salles'], static fn(array $a, array $b): int
            => ($rank[$a['disponibilite']] ?? 9) <=> ($rank[$b['disponibilite']] ?? 9)
                ?: strcasecmp($a['nom'], $b['nom']));
        $out['creneau'] = self::formatLocal($start, false) . ' → ' . $end->format('H:i');
        if (count($out['salles']) > self::ROOMS_AVAILABILITY_MAX) {
            $out['note'] = 'Disponibilité vérifiée pour ' . self::ROOMS_AVAILABILITY_MAX . ' salles au plus : '
                . 'précise `requete` pour viser les autres.';
        }
        return $out;
    }

    /**
     * Disponibilité de salles (ou de personnes) sur un créneau, par adresse en
     * minuscules : `libre`, `occupee` ou `inconnue` (agenda non communiqué).
     *
     * @param list<string> $addresses
     * @return array<string,string>
     */
    public function roomsAvailability(array $addresses, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $addresses = array_values(array_unique(array_filter(array_map('trim', $addresses), static fn(string $a): bool => $a !== '')));
        if ($addresses === []) {
            return [];
        }
        $utc  = new \DateTimeZone('UTC');
        $data = $this->apiJson('POST', self::ME . '/calendar/getSchedule', [
            'schedules' => array_slice($addresses, 0, self::ROOMS_AVAILABILITY_MAX),
            'startTime' => ['dateTime' => $from->setTimezone($utc)->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'endTime'   => ['dateTime' => $to->setTimezone($utc)->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'availabilityViewInterval' => 15,
        ]);
        return self::scheduleStatus($data, $addresses, $from, $to);
    }

    /**
     * Contrôle d'une salle AVANT la carte : est-elle libre sur le créneau de
     * la charge utile ? `[]` sans salle ou sur une journée entière (une salle
     * se réserve à l'heure) ; jamais d'exception — une vérification en panne
     * ne bloque pas la demande, elle se dit « inconnue ».
     *
     * @param array{nom:string,adresse:string}|null $room
     * @return array{salle_dispo?: string, salle_verifiee?: string, premiere_occurrence?: bool}
     */
    public function roomCheck(?array $room, array $bounds, bool $recurring = false): array
    {
        if ($room === null || !empty($bounds['isAllDay'])) {
            return [];
        }
        $from = self::parseBoundary($bounds['start'] ?? null, $this->timeZone);
        $to   = self::parseBoundary($bounds['end'] ?? null, $this->timeZone);
        if ($from === null || $to === null || $to <= $from) {
            return [];
        }
        try {
            $status = $this->roomsAvailability([$room['adresse']], $from, $to)[strtolower($room['adresse'])] ?? 'inconnue';
        } catch (\RuntimeException $e) {
            error_log(self::LOG_LABEL . ' disponibilité de salle non vérifiée : ' . $e->getMessage());
            $status = 'inconnue';
        }
        return ['salle_dispo' => $status, 'salle_verifiee' => self::roomLabel($room)] + ($recurring ? ['premiere_occurrence' => true] : []);
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
                'id,subject,bodyPreview,body,location,start,end,isAllDay,isCancelled,isOrganizer,'
                . 'organizer,attendees,webLink,isOnlineMeeting,onlineMeeting,onlineMeetingUrl,type,'
                . 'showAs,sensitivity,isReminderOn,reminderMinutesBeforeStart'
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
            $kind    = strtolower((string)($a['type'] ?? ''));
            $attendees[] = $ligne
                . ($kind === 'optional' ? ' (facultatif)' : '')
                // Une salle (ou un équipement) répond d'elle-même selon son agenda.
                . ($kind === 'resource' ? ' (salle ou ressource)' : '')
                . ($reponse === 'declined' ? ' (décliné)' : '')
                . ($reponse === 'tentativelyaccepted' ? ' (provisoire)' : '')
                . ($reponse === 'accepted' ? ' (accepté)' : '');
        }

        $allDay = !empty($ev['isAllDay']);
        // Lien de connexion : `onlineMeeting.joinUrl` (la doc annonce le retrait
        // d'`onlineMeetingUrl`, gardé en repli pour les anciennes réunions Skype).
        $visio  = trim((string)($ev['onlineMeeting']['joinUrl'] ?? ''));
        $type   = (string)($ev['type'] ?? '');

        return [
            'id'              => (string)($ev['id'] ?? $id),
            'titre'           => trim((string)($ev['subject'] ?? '')) ?: '(sans titre)',
            'debut'           => self::formatEventBoundary($ev['start'] ?? null, $allDay, $this->timeZone),
            'fin'             => self::formatEventEnd($ev['end'] ?? null, $allDay, $this->timeZone),
            'journee_entiere' => $allDay,
            'annule'          => !empty($ev['isCancelled']),
            'lieu'            => trim((string)($ev['location']['displayName'] ?? '')),
            'salle'           => self::roomStatusLine($ev),
            'organisateur'    => UserOutlookService::formatRecipient($ev['organizer'] ?? []),
            'description'     => $desc,
            'participants'    => $attendees,
            'visio'           => $visio !== '' ? $visio : trim((string)($ev['onlineMeetingUrl'] ?? '')),
            'reunion_en_ligne' => !empty($ev['isOnlineMeeting']),
            // Seul l'organisateur modifie titre, horaire, lieu et invités.
            'vous_organisez'  => !array_key_exists('isOrganizer', $ev) || !empty($ev['isOrganizer']),
            'recurrent'       => in_array($type, ['occurrence', 'exception', 'seriesMaster'], true),
            'disponibilite'   => (string)(array_search((string)($ev['showAs'] ?? ''), self::SHOW_AS, true) ?: ''),
            'prive'           => strtolower((string)($ev['sensitivity'] ?? '')) === 'private',
            'rappel_minutes'  => !empty($ev['isReminderOn']) ? (int)($ev['reminderMinutesBeforeStart'] ?? 0) : null,
            'lien'            => (string)($ev['webLink'] ?? ''),
            'tronque'         => $truncated,
        ];
    }

    /**
     * Crée un évènement (appelé uniquement depuis la file d'approbation, au clic
     * « Approuver »). Avec des invités, Outlook leur envoie l'invitation
     * aussitôt (piège 4) : la carte l'a annoncé.
     *
     * @return array{id:string,titre:string,quand:string,url:string,agenda:string,invites:list<string>,salle:string,visio:string,visio_demandee:bool}
     */
    public function createEvent(array $args): array
    {
        if (!$this->canWrite()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $calendarId = self::normalizeCalendarId((string)($args['agenda'] ?? ''));
        $payload    = self::buildEventPayload($args, $this->accountEmail);
        $room       = self::parseRoom($args['salle'] ?? null);

        $ev = $this->apiJson('POST', self::calendarPath($calendarId) . '/events', $payload);
        if ((string)($ev['id'] ?? '') === '') {
            throw new \RuntimeException('Création de l\'évènement refusée par le calendrier Outlook.');
        }
        // Trace de succès (id tronqué seul, jamais de contenu) — diagnostic staging.
        error_log('[Agenda Outlook user] évènement créé id=' . mb_substr((string)$ev['id'], 0, 24) . '…'
            . ' invités=' . count($payload['attendees'] ?? [])
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return [
            'id'     => (string)$ev['id'],
            'titre'  => trim((string)($ev['subject'] ?? ($args['titre'] ?? ''))) ?: '(sans titre)',
            'quand'  => self::formatEventBoundary($ev['start'] ?? null, !empty($ev['isAllDay']), UserCalendarService::argTimeZone($args)),
            'url'    => self::eventUrl($ev),
            'agenda' => $calendarId,
            // Les PERSONNES invitées ; la salle a sa ligne (elle répond d'elle-même).
            'invites' => self::attendeeAddresses((array)($payload['attendees'] ?? []), false),
            'salle'  => $room !== null ? self::roomLabel($room) : '',
            // Un compte sans Teams (compte personnel) crée l'évènement sans
            // réunion en ligne : on le constate dans la réponse, on ne le suppose pas.
            'visio'  => trim((string)($ev['onlineMeeting']['joinUrl'] ?? '')),
            'visio_demandee' => !empty($payload['isOnlineMeeting']),
        ];
    }

    /**
     * Évènement tel qu'il est AVANT une modification : ce qu'il faut pour
     * recomposer les invités, garder la durée et savoir qui organise.
     * Throw RuntimeException à message FR.
     */
    public function fetchForUpdate(string $eventId): array
    {
        $id = self::assertEventId($eventId);
        $ev = $this->apiJson(
            'GET',
            self::ME . '/events/' . rawurlencode($id) . '?$select=' . rawurlencode(self::UPDATE_SELECT)
        );
        if ((string)($ev['id'] ?? '') === '') {
            throw new \RuntimeException(self::MSG_NOT_FOUND);
        }
        return $ev;
    }

    /**
     * Contrôle d'une modification AVANT la carte (appelé par le handler de
     * l'outil) : l'évènement est relu, la demande validée contre son état, et
     * la carte reçoit de quoi le nommer et dire qui sera prévenu.
     *
     * @return array{titre:string,quand:string,lien:string,serie:string,previent:bool,invites:list<string>,ajoutes:list<string>,retires:list<string>,salle:string,salle_retiree:string,salle_gardee:string,salle_dispo?:string,salle_verifiee?:string,premiere_occurrence?:bool}
     */
    public function inspectUpdate(array $args): array
    {
        if (!$this->canWrite()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $current = $this->fetchForUpdate((string)($args['evenement'] ?? ''));
        $plan    = self::buildUpdatePayload($args, $current, $this->accountEmail);
        $allDay  = !empty($current['isAllDay']);
        $tz      = UserCalendarService::argTimeZone($args);

        // Salle libre sur l'horaire qu'aura la réunion ? Une nouvelle salle,
        // toujours ; une salle GARDÉE, seulement si la réunion part sur un
        // créneau qui ne chevauche pas l'ancien — sinon son agenda la verrait
        // occupée… par cette même réunion.
        $payload = $plan['payload'];
        $bounds  = [
            'isAllDay' => array_key_exists('isAllDay', $payload) ? $payload['isAllDay'] : $allDay,
            'start'    => $payload['start'] ?? ($current['start'] ?? null),
            'end'      => $payload['end'] ?? ($current['end'] ?? null),
        ];
        $toCheck = $plan['salle'];
        if ($toCheck === '' && $plan['salle_gardee'] !== '' && isset($payload['start'])) {
            $oldS = self::parseBoundary($current['start'] ?? null);
            $oldE = self::parseBoundary($current['end'] ?? null);
            $newS = self::parseBoundary($payload['start']);
            $newE = self::parseBoundary($payload['end'] ?? null);
            if ($oldS !== null && $oldE !== null && $newS !== null && $newE !== null && ($newS >= $oldE || $newE <= $oldS)) {
                $toCheck = $plan['salle_gardee'];
            }
        }
        $check = $toCheck !== ''
            ? $this->roomCheck(self::parseRoom($toCheck), $bounds, (string)($current['type'] ?? '') === 'seriesMaster')
            : [];

        return [
            'titre'    => trim((string)($current['subject'] ?? '')) ?: '(sans titre)',
            'quand'    => self::formatEventBoundary($current['start'] ?? null, $allDay, $tz),
            'lien'     => self::eventUrl($current),
            'serie'    => (string)($current['type'] ?? ''),
            'previent' => $plan['previent'],
            'invites'  => $plan['invites'],
            'ajoutes'  => $plan['ajoutes'],
            'retires'  => $plan['retires'],
            'salle'    => $plan['salle'],
            'salle_retiree' => $plan['salle_retiree'],
            'salle_gardee'  => $plan['salle_gardee'],
        ] + $check;
    }

    /**
     * Modifie un évènement (file d'approbation, au clic « Approuver »).
     * L'évènement est RELU à l'exécution : la liste des invités est recomposée
     * sur son état du moment, pas sur celui d'avant la carte (piège 5).
     *
     * @return array{id:string,titre:string,quand:string,url:string,previent:bool,invites:list<string>,ajoutes:list<string>,retires:list<string>,salle:string,salle_retiree:string,visio:string}
     */
    public function updateEvent(array $args): array
    {
        if (!$this->canWrite()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $current = $this->fetchForUpdate((string)($args['evenement'] ?? ''));
        $plan    = self::buildUpdatePayload($args, $current, $this->accountEmail);
        $id      = (string)$current['id'];

        $ev = $this->apiJson('PATCH', self::ME . '/events/' . rawurlencode($id), $plan['payload']);
        // Graph répond 200 avec l'évènement à jour : sans lui, rien ne prouve
        // que la modification a eu lieu — on ne l'annonce pas.
        if ((string)($ev['id'] ?? '') === '') {
            throw new \RuntimeException('Modification refusée par le calendrier Outlook : l\'évènement n\'a pas été rendu à jour.');
        }
        error_log('[Agenda Outlook user] évènement modifié id=' . mb_substr($id, 0, 24) . '…'
            . ' champs=' . implode(',', array_keys($plan['payload']))
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return [
            'id'       => (string)$ev['id'],
            'titre'    => trim((string)($ev['subject'] ?? ($current['subject'] ?? ''))) ?: '(sans titre)',
            'quand'    => self::formatEventBoundary(
                $ev['start'] ?? ($current['start'] ?? null),
                array_key_exists('isAllDay', $ev) ? !empty($ev['isAllDay']) : !empty($current['isAllDay']),
                UserCalendarService::argTimeZone($args)
            ),
            'url'      => self::eventUrl($ev + ['webLink' => (string)($current['webLink'] ?? '')]),
            'previent' => $plan['previent'],
            'invites'  => $plan['invites'],
            'ajoutes'  => $plan['ajoutes'],
            'retires'  => $plan['retires'],
            'salle'    => $plan['salle'],
            'salle_retiree' => $plan['salle_retiree'],
            'visio'    => trim((string)($ev['onlineMeeting']['joinUrl'] ?? '')),
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
     * `timeZone` de l'utilisateur (fin absente → début + 1 h).
     *
     * Invités (`invites`, `invites_optionnels`), réunion Teams (`visio`),
     * récurrence, rappel, disponibilité, importance, confidentialité : cf. les
     * fonctions dédiées. Throw RuntimeException à message FR sur toute entrée
     * invalide — avant la carte, jamais après l'approbation.
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
        $allDay  = self::truthy($args['journee_entiere'] ?? null)
            || ($dayOnly && ($finRaw === '' || self::isDayOnly($finRaw)));

        // Heures lues dans le fuseau de l'UTILISATEUR (cf. UserCalendarService::argTimeZone).
        $tzName  = UserCalendarService::argTimeZone($args);
        $payload = ['subject' => mb_substr($titre, 0, 255)];

        if ($allDay) {
            if (!$dayOnly || ($finRaw !== '' && !self::isDayOnly($finRaw))) {
                throw new \RuntimeException('Un évènement « journée entière » attend des dates sans heure (AAAA-MM-JJ).');
            }
            $start = UserCalendarService::parseDateInput($debutRaw, false, $tzName);
            $end   = $finRaw !== '' ? UserCalendarService::parseDateInput($finRaw, false, $tzName) : $start;
            if ($start === null || $end === null || $end < $start) {
                throw new \RuntimeException('La date de fin doit être postérieure ou égale à la date de début.');
            }
            $payload += self::allDayBounds($start, $end, $tzName);
        } else {
            $start = UserCalendarService::parseDateInput($debutRaw, false, $tzName);
            if ($start === null || $dayOnly) {
                throw new \RuntimeException('Heure de début manquante (format AAAA-MM-JJ HH:MM), ou passe journee_entiere=true.');
            }
            $end = $finRaw !== '' ? UserCalendarService::parseDateInput($finRaw, false, $tzName) : $start->modify('+1 hour');
            if ($end === null || $end <= $start) {
                throw new \RuntimeException('L\'heure de fin doit être postérieure à l\'heure de début.');
            }
            $payload += self::timedBounds($start, $end, $tzName);
        }

        $desc = trim((string)($args['description'] ?? ''));
        if ($desc !== '') {
            // Texte brut : jamais de HTML fabriqué depuis le texte du modèle.
            $payload['body'] = ['contentType' => 'Text', 'content' => mb_substr($desc, 0, 8000)];
        }
        // Salle (piège 8) : déjà résolue par le handler (« Nom <adresse> ») —
        // elle devient le lieu ET une invitée de type ressource.
        $room = self::parseRoom($args['salle'] ?? null);
        $lieu = trim((string)($args['lieu'] ?? ''));
        if ($room !== null) {
            $payload['location'] = self::roomLocation($room, $lieu);
        } elseif ($lieu !== '') {
            $payload['location'] = ['displayName' => mb_substr($lieu, 0, 1000)];
        }

        // Invités : `participants` reste lu (ancien nom, et celui que le modèle
        // emploie spontanément) — l'ignorer laisserait croire des invités conviés.
        $required = self::parseAddresses($args['invites'] ?? ($args['participants'] ?? null));
        $optional = self::parseAddresses($args['invites_optionnels'] ?? null);
        $attendees = self::attendeeList($required, $optional, $accountEmail, $room);
        if (count($attendees) > self::MAX_ATTENDEES) {
            throw new \RuntimeException('Trop d\'invités (' . count($attendees) . ') : ' . self::MAX_ATTENDEES
                . ' au plus par évènement. Invite une liste de diffusion, ou réduis la liste.');
        }
        if ($attendees !== []) {
            $payload['attendees'] = $attendees;
        }

        $payload += self::optionsPayload($args);

        $recurrence = self::recurrencePayload($args, $start);
        if ($recurrence !== null) {
            $payload['recurrence'] = $recurrence;
        }
        return $payload;
    }

    /**
     * Arguments de l'outil modifier_evenement_agenda_outlook + évènement tel
     * qu'il est (`fetchForUpdate`) → corps du PATCH, limité à ce qui CHANGE.
     *
     *  - horaire : `debut` seul garde la DURÉE (une date sans heure déplace le
     *    jour en gardant l'heure) ; `fin` seule garde le début ;
     *  - invités : la liste envoyée est la liste ENTIÈRE recomposée (piège 5) ;
     *  - salle (piège 8) : `salle` remplace l'actuelle (retirée des invités,
     *    la nouvelle y entre en ressource et devient le lieu), `retirer_salle`
     *    la libère ; un nouveau `lieu` reste rattaché à la salle gardée ;
     *  - qui n'organise pas ne règle que son rappel et sa disponibilité ;
     *  - description d'une réunion en ligne : refusée (piège 6).
     *
     * `previent` : Outlook écrira-t-il à des invités ? (carte, tâches sans
     * validation). Throw RuntimeException à message FR.
     *
     * @return array{payload: array<string,mixed>, previent: bool, invites: list<string>, ajoutes: list<string>, retires: list<string>, salle: string, salle_retiree: string, salle_gardee: string}
     */
    public static function buildUpdatePayload(array $args, array $current, string $accountEmail = ''): array
    {
        if (!empty($current['isCancelled'])) {
            throw new \RuntimeException('Cet évènement est annulé : il ne se modifie plus.');
        }
        $has = static function (string $k) use ($args): bool {
            $v = $args[$k] ?? null;
            if (is_array($v)) {
                return $v !== [];
            }
            return is_scalar($v) && !is_bool($v) && trim((string)$v) !== '';
        };
        $wantAllDay    = self::truthy($args['journee_entiere'] ?? null);
        $wantVisio     = self::truthy($args['visio'] ?? null);
        $wantRoomOut   = self::truthy($args['retirer_salle'] ?? null);

        $content = array_filter(
            ['titre', 'debut', 'fin', 'lieu', 'description', 'ajouter_invites', 'ajouter_invites_optionnels', 'retirer_invites', 'salle'],
            $has
        );
        $organizer = !array_key_exists('isOrganizer', $current) || !empty($current['isOrganizer']);
        if (!$organizer && ($content !== [] || $wantAllDay || $wantVisio || $wantRoomOut)) {
            $qui = UserOutlookService::formatRecipient($current['organizer'] ?? []);
            throw new \RuntimeException(
                'Seul l\'organisateur' . ($qui !== '' ? ' (' . $qui . ')' : '') . ' peut modifier le titre, l\'horaire, '
                . 'le lieu, la salle, la description ou les invités de cet évènement. Dans l\'agenda de l\'utilisateur, '
                . 'tu peux seulement régler son rappel et sa disponibilité affichée.'
            );
        }

        // Salle (piège 8) : l'actuelle, la nouvelle (déjà résolue par le handler).
        $curRoom = self::currentRoom($current);
        $newRoom = self::parseRoom($args['salle'] ?? null);
        if ($newRoom !== null && $wantRoomOut) {
            throw new \RuntimeException('Choisis : `salle` remplace la salle actuelle, `retirer_salle` la libère — pas les deux.');
        }
        if ($wantRoomOut && $curRoom === null) {
            throw new \RuntimeException('Cet évènement n\'a pas de salle réservée : rien à libérer.');
        }
        if ($newRoom !== null && $curRoom !== null && strcasecmp($newRoom['adresse'], $curRoom['adresse']) === 0) {
            $newRoom = null; // déjà dans cette salle
        }

        $tzName  = UserCalendarService::argTimeZone($args);
        $payload = [];

        if ($has('titre')) {
            $payload['subject'] = mb_substr(trim((string)$args['titre']), 0, 255);
        }
        if ($has('debut') || $has('fin') || $wantAllDay) {
            $payload += self::updatedBounds(
                $has('debut') ? trim((string)$args['debut']) : '',
                $has('fin') ? trim((string)$args['fin']) : '',
                $wantAllDay,
                $current,
                $tzName
            );
        }
        // Lieu : posé plus bas, une fois la salle d'après connue (un nouveau
        // lieu ne doit pas faire oublier la salle à Outlook, ni l'inverse).
        if ($has('description')) {
            if (!empty($current['isOnlineMeeting'])) {
                throw new \RuntimeException(
                    'La description d\'une réunion en ligne porte son lien de connexion : la réécrire le ferait '
                    . 'disparaître. Modifie le reste, et invite l\'utilisateur à retoucher la description dans Outlook.'
                );
            }
            $payload['body'] = ['contentType' => 'Text', 'content' => mb_substr(trim((string)$args['description']), 0, 8000)];
        }

        // Invités : état actuel, puis retraits et ajouts.
        $list = [];
        foreach ((array)($current['attendees'] ?? []) as $a) {
            $addr = is_array($a) ? trim((string)($a['emailAddress']['address'] ?? '')) : '';
            if ($addr === '') {
                continue;
            }
            $type = strtolower((string)($a['type'] ?? ''));
            $list[strtolower($addr)] = [
                'address' => $addr,
                'name'    => trim((string)($a['emailAddress']['name'] ?? '')),
                'type'    => in_array($type, ['optional', 'resource'], true) ? $type : 'required',
            ];
        }
        $ajoutes = [];
        $retires = [];
        $changed = false;
        $roomOut = '';
        $roomIn  = '';
        foreach (self::parseAddresses($args['retirer_invites'] ?? null) as $addr) {
            $k = strtolower($addr);
            if (!isset($list[$k])) {
                throw new \RuntimeException('« ' . $addr . ' » ne fait pas partie des invités de cet évènement — lis-le '
                    . 'avec lire_evenement_agenda_outlook pour voir qui est invité.');
            }
            // La salle retirée par son adresse : c'est la libérer.
            if ($curRoom !== null && $k === strtolower($curRoom['adresse'])) {
                $wantRoomOut = true;
                continue;
            }
            $retires[] = $list[$k]['address'];
            unset($list[$k]);
            $changed = true;
        }
        // La salle quitte la réunion (libérée ou remplacée) ; la nouvelle y entre.
        if ($curRoom !== null && ($wantRoomOut || $newRoom !== null)) {
            unset($list[strtolower($curRoom['adresse'])]);
            $roomOut = self::roomLabel($curRoom);
            $changed = true;
        }
        $roomKey = $newRoom !== null ? strtolower($newRoom['adresse']) : '';
        if ($newRoom !== null) {
            $list[$roomKey] = ['address' => $newRoom['adresse'], 'name' => $newRoom['nom'] === $newRoom['adresse'] ? '' : $newRoom['nom'], 'type' => 'resource'];
            $roomIn  = self::roomLabel($newRoom);
            $changed = true;
        }
        $moi = strtolower(trim($accountEmail));
        foreach ([
            'required' => self::parseAddresses($args['ajouter_invites'] ?? null),
            'optional' => self::parseAddresses($args['ajouter_invites_optionnels'] ?? null),
        ] as $type => $addrs) {
            foreach ($addrs as $addr) {
                $k = strtolower($addr);
                if ($k === $moi || (isset($list[$k]) && $list[$k]['type'] === $type)
                    || (isset($list[$k]) && $list[$k]['type'] === 'resource')) {
                    continue; // l'organisateur, déjà invité au même titre, ou une salle
                }
                if (!isset($list[$k])) {
                    $ajoutes[] = $addr;
                }
                $list[$k] = ['address' => $list[$k]['address'] ?? $addr, 'name' => $list[$k]['name'] ?? '', 'type' => $type];
                $changed = true;
            }
        }

        // Lieu : celui demandé, rattaché à la salle d'après s'il y en a une
        // (sinon un nouveau lieu ferait oublier la salle à Outlook) ; une salle
        // libérée sans nouveau lieu laisse le lieu vide.
        $roomAfter = $newRoom ?? ($wantRoomOut ? null : $curRoom);
        $lieu      = $has('lieu') ? trim((string)$args['lieu']) : '';
        if ($roomIn !== '' || $roomOut !== '' || $has('lieu')) {
            $payload['location'] = $roomAfter !== null
                ? self::roomLocation($roomAfter, $lieu)
                : ['displayName' => mb_substr($lieu, 0, 1000)];
        }

        if ($changed) {
            if (count($list) > self::MAX_ATTENDEES) {
                throw new \RuntimeException('Trop d\'invités (' . count($list) . ') : ' . self::MAX_ATTENDEES . ' au plus par évènement.');
            }
            $payload['attendees'] = array_values(array_map(
                static fn(array $a): array => [
                    'emailAddress' => array_filter(['address' => $a['address'], 'name' => $a['name']], static fn(string $v): bool => $v !== ''),
                    'type'         => $a['type'],
                ],
                $list
            ));
        }

        $payload += self::optionsPayload($args);

        if ($payload === []) {
            throw new \RuntimeException('Aucune modification demandée : précise ce qui change (titre, horaire, lieu, '
                . 'salle, description, invités, réunion Teams, rappel, disponibilité…). Une salle déjà réservée '
                . 'pour cette réunion n\'est pas un changement.');
        }

        // Outlook écrit aux invités dès que la réunion change pour eux — la
        // salle comprise : elle reçoit la mise à jour et la réaccepte (ou non).
        $visible  = array_intersect(array_keys($payload), ['subject', 'start', 'end', 'isAllDay', 'location', 'body', 'isOnlineMeeting']);
        $previent = $changed || ($list !== [] && $visible !== []);

        return [
            'payload'  => $payload,
            'previent' => $previent,
            // Les PERSONNES invitées ; la salle a ses lignes.
            'invites'  => array_values(array_map(
                static fn(array $a): string => $a['address'],
                array_filter($list, static fn(array $a): bool => $a['type'] !== 'resource')
            )),
            'ajoutes'  => $ajoutes,
            'retires'  => $retires,
            'salle'    => $roomIn,
            'salle_retiree' => $roomOut,
            // Salle gardée par la réunion après la modification (pour la carte).
            'salle_gardee' => $roomAfter !== null && $roomIn === '' ? self::roomLabel($roomAfter) : '',
        ];
    }

    /**
     * Nouvel horaire d'un évènement existant (cf. buildUpdatePayload).
     *
     * @return array{isAllDay: bool, start: array, end: array}
     */
    private static function updatedBounds(string $debut, string $fin, bool $wantAllDay, array $current, string $tzName): array
    {
        $curAllDay = !empty($current['isAllDay']);
        $curStart  = self::localBoundary($current['start'] ?? null, $curAllDay, $tzName);
        $curEnd    = self::localBoundary($current['end'] ?? null, $curAllDay, $tzName);
        if ($curStart === null || $curEnd === null) {
            throw new \RuntimeException('Horaire actuel de l\'évènement illisible : donne `debut` ET `fin`.');
        }
        // Dernier jour réellement occupé (la fin d'une journée entière est exclusive).
        $curLast = $curAllDay ? $curEnd->modify('-1 day') : $curEnd;

        $debutDay = $debut !== '' && self::isDayOnly($debut);
        $finDay   = $fin !== '' && self::isDayOnly($fin);

        if ($wantAllDay || ($curAllDay && ($debut === '' || $debutDay) && ($fin === '' || $finDay))) {
            if (($debut !== '' && !$debutDay) || ($fin !== '' && !$finDay)) {
                throw new \RuntimeException('Un évènement « journée entière » attend des dates sans heure (AAAA-MM-JJ).');
            }
            $start = $debut !== ''
                ? UserCalendarService::parseDateInput($debut, false, $tzName)
                : UserCalendarService::parseDateInput($curStart->format('Y-m-d'), false, $tzName);
            if ($fin !== '') {
                $last = UserCalendarService::parseDateInput($fin, false, $tzName);
            } elseif ($debut !== '') {
                // Début déplacé : même nombre de jours.
                $days = max(0, (int)$curStart->setTime(0, 0)->diff($curLast->setTime(0, 0))->days);
                $last = $start?->modify('+' . $days . ' days');
            } else {
                $last = UserCalendarService::parseDateInput($curLast->format('Y-m-d'), false, $tzName);
            }
            if ($start === null || $last === null || $last < $start) {
                throw new \RuntimeException('La date de fin doit être postérieure ou égale à la date de début.');
            }
            return self::allDayBounds($start, $last, $tzName);
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
            $start = UserCalendarService::parseDateInput($debut . ' ' . $curStart->format('H:i'), false, $tzName);
        } else {
            $start = UserCalendarService::parseDateInput($debut, false, $tzName);
        }
        if ($fin === '') {
            $end = $start?->modify('+' . $duration . ' seconds');
        } elseif ($finDay) {
            throw new \RuntimeException('Heure de fin manquante (format AAAA-MM-JJ HH:MM).');
        } else {
            $end = UserCalendarService::parseDateInput($fin, false, $tzName);
        }
        if ($start === null || $end === null || $end <= $start) {
            throw new \RuntimeException('L\'heure de fin doit être postérieure à l\'heure de début.');
        }
        return self::timedBounds($start, $end, $tzName);
    }

    /**
     * Borne d'un évènement dans le fuseau de l'utilisateur. Une journée entière
     * se lit par sa DATE : Graph la rend à minuit dans un fuseau (UTC par
     * défaut) — la convertir la ferait reculer d'un jour à l'ouest de Greenwich.
     */
    private static function localBoundary(mixed $node, bool $allDay, string $timeZone): ?\DateTimeImmutable
    {
        if ($allDay && is_array($node)
            && preg_match('/^(\d{4}-\d{2}-\d{2})/', trim((string)($node['dateTime'] ?? '')), $m) === 1) {
            try {
                return UserCalendarService::parseDateInput($m[1], false, $timeZone);
            } catch (\Throwable) {
                return null;
            }
        }
        return self::parseBoundary($node, $timeZone);
    }

    /**
     * Bornes d'un évènement horodaté : heure LOCALE + fuseau nommé (un RFC3339
     * dans `dateTime` serait refusé). Le fuseau est l'un de ceux que la doc
     * Graph accepte en écriture (cf. graphWriteZone) ; à défaut, l'instant est
     * envoyé en UTC — toujours accepté, et c'est le même instant.
     *
     * @return array{isAllDay: bool, start: array, end: array}
     */
    public static function timedBounds(\DateTimeImmutable $start, \DateTimeImmutable $end, string $tzName): array
    {
        $zone = self::graphWriteZone($tzName, $start);
        if ($zone === '') {
            $utc   = new \DateTimeZone('UTC');
            $start = $start->setTimezone($utc);
            $end   = $end->setTimezone($utc);
            $zone  = 'UTC';
        }
        return [
            'isAllDay' => false,
            'start'    => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $zone],
            'end'      => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => $zone],
        ];
    }

    /**
     * Bornes d'une journée entière : minuit, même fuseau, fin EXCLUSIVE (le
     * lendemain du dernier jour) — les trois sont exigés par Graph.
     *
     * @return array{isAllDay: bool, start: array, end: array}
     */
    public static function allDayBounds(\DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay, string $tzName): array
    {
        $zone = self::graphWriteZone($tzName, $firstDay);
        $zone = $zone !== '' ? $zone : 'UTC';

        return [
            'isAllDay' => true,
            'start'    => ['dateTime' => $firstDay->format('Y-m-d') . 'T00:00:00', 'timeZone' => $zone],
            'end'      => ['dateTime' => $lastDay->modify('+1 day')->format('Y-m-d') . 'T00:00:00', 'timeZone' => $zone],
        ];
    }

    /**
     * Fuseaux IANA que la doc Graph (ressource dateTimeTimeZone, « Additional
     * time zones », relue le 2026-11-13) dit acceptés EN PLUS des noms Windows.
     * « Methods such as create or update might not support all dateTimeTimeZone
     * time zones » : un fuseau de navigateur hors de cette liste
     * (Europe/Brussels, Europe/Madrid, America/Toronto…) n'est pas garanti.
     */
    private const GRAPH_ZONES = [
        'Europe/Paris', 'Europe/London', 'Europe/Berlin', 'Europe/Budapest', 'Europe/Warsaw', 'Europe/Bucharest',
        'Europe/Kyiv', 'Europe/Istanbul', 'Europe/Kaliningrad', 'Europe/Moscow',
        'Etc/GMT', 'Atlantic/Reykjavik', 'Atlantic/Azores', 'Atlantic/Cape_Verde',
        'Africa/Casablanca', 'Africa/Lagos', 'Africa/Windhoek', 'Africa/Cairo', 'Africa/Johannesburg', 'Africa/Nairobi',
        'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Phoenix',
        'America/Anchorage', 'Pacific/Honolulu', 'America/Halifax', 'America/St_Johns', 'America/Regina',
        'America/Indiana/Indianapolis', 'America/Mexico_City', 'America/Chihuahua', 'America/Guatemala',
        'America/Bogota', 'America/Caracas', 'America/Asuncion', 'America/Cuiaba', 'America/La_Paz',
        'America/Santiago', 'America/Sao_Paulo', 'America/Argentina/Buenos_Aires', 'America/Cayenne',
        'America/Montevideo', 'America/Bahia',
        'Asia/Beirut', 'Asia/Damascus', 'Asia/Jerusalem', 'Asia/Amman', 'Asia/Baghdad', 'Asia/Riyadh', 'Asia/Tehran',
        'Asia/Dubai', 'Asia/Baku', 'Indian/Mauritius', 'Asia/Tbilisi', 'Asia/Yerevan', 'Asia/Kabul', 'Asia/Karachi',
        'Asia/Kolkata', 'Asia/Colombo', 'Asia/Kathmandu', 'Asia/Dhaka', 'Asia/Yekaterinburg', 'Asia/Bangkok',
        'Asia/Novosibirsk', 'Asia/Shanghai', 'Asia/Krasnoyarsk', 'Asia/Singapore', 'Asia/Taipei', 'Asia/Ulaanbaatar',
        'Asia/Irkutsk', 'Asia/Tokyo', 'Asia/Seoul', 'Asia/Yakutsk', 'Asia/Vladivostok', 'Asia/Magadan',
        'Australia/Perth', 'Australia/Adelaide', 'Australia/Darwin', 'Australia/Brisbane', 'Australia/Sydney',
        'Australia/Hobart', 'Pacific/Port_Moresby', 'Pacific/Guadalcanal', 'Pacific/Auckland', 'Pacific/Fiji',
        'Pacific/Tongatapu', 'Pacific/Apia', 'Pacific/Kiritimati',
        'Etc/GMT+12', 'Etc/GMT+11', 'Etc/GMT+2', 'Etc/GMT-12',
    ];

    /**
     * Fuseau à ÉCRIRE dans une requête Graph pour un fuseau de navigateur :
     * lui-même s'il est documenté, sinon un fuseau documenté qui a EXACTEMENT
     * les mêmes heures sur les treize mois qui suivent (Europe/Brussels →
     * Europe/Paris : l'heure locale envoyée ne change pas, seul le nom).
     * '' si aucun ne convient : l'appelant envoie alors l'instant en UTC.
     */
    public static function graphWriteZone(string $tzName, \DateTimeImmutable $at): string
    {
        if ($tzName === 'UTC' || in_array($tzName, self::GRAPH_ZONES, true)) {
            return $tzName;
        }
        try {
            $source = new \DateTimeZone($tzName);
        } catch (\Throwable) {
            return '';
        }
        $from = $at->getTimestamp() - 86400;
        $to   = $from + 400 * 86400;
        $want = self::zoneSignature($source, $from, $to);
        if ($want === self::zoneSignature(new \DateTimeZone('UTC'), $from, $to)) {
            return 'UTC';
        }
        foreach (self::GRAPH_ZONES as $candidate) {
            try {
                if (self::zoneSignature(new \DateTimeZone($candidate), $from, $to) === $want) {
                    return $candidate;
                }
            } catch (\Throwable) {
                continue; // nom inconnu de cette version de PHP
            }
        }
        return '';
    }

    /** Décalages d'un fuseau sur une période (départ + chaque changement d'heure). */
    private static function zoneSignature(\DateTimeZone $zone, int $from, int $to): string
    {
        $out = [];
        foreach ((array)$zone->getTransitions($from, $to) as $t) {
            $out[] = (int)$t['ts'] . ':' . (int)$t['offset'];
        }
        return implode('|', $out);
    }

    /**
     * Adresses d'invités depuis ce que le modèle a écrit : liste, ou texte
     * séparé par virgules / points-virgules / lignes ; « Nom <adresse> » réduit
     * à l'adresse ; doublons retirés (sans casse). Une adresse invalide LÈVE :
     * l'ignorer inviterait moins de monde que demandé, sans le dire.
     *
     * @return list<string>
     */
    public static function parseAddresses(mixed $raw): array
    {
        // Source unique, partagée avec l'agenda Google (mêmes règles, même message).
        return UserCalendarService::parseAddresses($raw);
    }

    /**
     * Invités d'une création, forme Graph. Un invité cité deux fois est
     * obligatoire ; l'organisateur ne s'invite pas lui-même. La salle, s'il y
     * en a une, part en dernier, de type `resource` (piège 8) — citée aussi
     * parmi les invités, elle n'est invitée qu'une fois, comme salle.
     *
     * @param list<string> $required
     * @param list<string> $optional
     * @param array{nom:string,adresse:string}|null $room
     * @return list<array{emailAddress: array{address: string, name?: string}, type: string}>
     */
    public static function attendeeList(array $required, array $optional, string $accountEmail = '', ?array $room = null): array
    {
        $moi  = strtolower(trim($accountEmail));
        $seen = $room !== null ? [strtolower($room['adresse']) => true] : [];
        $out  = [];
        foreach (['required' => $required, 'optional' => $optional] as $type => $addrs) {
            foreach ($addrs as $addr) {
                $k = strtolower($addr);
                if ($k === $moi || isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $out[]    = ['emailAddress' => ['address' => $addr], 'type' => $type];
            }
        }
        if ($room !== null) {
            $out[] = self::roomAttendee($room);
        }
        return $out;
    }

    /**
     * Adresses d'une liste d'invités Graph ; `$withResources` faux = les
     * PERSONNES seulement (une salle a sa propre ligne sur la carte).
     *
     * @return list<string>
     */
    public static function attendeeAddresses(array $attendees, bool $withResources = true): array
    {
        $out = [];
        foreach ($attendees as $a) {
            $addr = is_array($a) ? trim((string)($a['emailAddress']['address'] ?? '')) : '';
            if ($addr === '' || (!$withResources && strtolower((string)($a['type'] ?? '')) === 'resource')) {
                continue;
            }
            $out[] = $addr;
        }
        return $out;
    }

    // ---- Salles (piège 8) ----------------------------------------------------

    /**
     * Salle écrite par le handler (« Nom <adresse> ») ou par le modèle
     * (adresse seule) → `{nom, adresse}` ; null si rien. Un NOM sans adresse
     * n'est pas une salle réservable : `$strict` le refuse (la charge utile
     * ne doit jamais partir sans l'adresse), sinon null (le handler le
     * cherchera dans l'annuaire).
     *
     * @return array{nom:string,adresse:string}|null
     */
    public static function parseRoom(mixed $raw, bool $strict = true): ?array
    {
        if (is_array($raw)) {
            $raw = ($raw['nom'] ?? $raw['name'] ?? '') !== ''
                ? ($raw['nom'] ?? $raw['name']) . ' <' . ($raw['adresse'] ?? $raw['address'] ?? $raw['email'] ?? '') . '>'
                : ($raw['adresse'] ?? $raw['address'] ?? $raw['email'] ?? '');
        }
        if (!is_scalar($raw) || is_bool($raw)) {
            return null;
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return null;
        }
        $name = '';
        $addr = $s;
        if (preg_match('/^(.*?)\s*<([^<>]+)>\s*$/u', $s, $m) === 1) {
            $name = trim($m[1], " \t\"'");
            $addr = trim($m[2]);
        }
        if (mb_strlen($addr) > 254 || filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
            if ($strict) {
                throw new \RuntimeException('Salle « ' . mb_substr($s, 0, 80) . ' » sans adresse : cherche-la avec '
                    . 'lister_salles_outlook, ou demande son adresse email à l\'utilisateur.');
            }
            return null;
        }
        $name = mb_substr($name !== '' ? $name : $addr, 0, 200);
        return ['nom' => $name, 'adresse' => $addr];
    }

    /** « Nom <adresse> », ou l'adresse seule quand la salle n'a pas d'autre nom. */
    public static function roomLabel(array $room): string
    {
        $nom  = trim((string)($room['nom'] ?? ''));
        $addr = trim((string)($room['adresse'] ?? ''));
        return ($nom === '' || strcasecmp($nom, $addr) === 0) ? $addr : $nom . ' <' . $addr . '>';
    }

    /** Invitée Graph d'une salle : type `resource` (doc Create event, piège 8). */
    public static function roomAttendee(array $room): array
    {
        $email = ['address' => (string)$room['adresse']];
        if (($room['nom'] ?? '') !== '' && strcasecmp((string)$room['nom'], (string)$room['adresse']) !== 0) {
            $email['name'] = (string)$room['nom'];
        }
        return ['emailAddress' => $email, 'type' => 'resource'];
    }

    /**
     * Lieu d'une réunion dans une salle : son nom et son adresse
     * (`locationEmailAddress`, ce qui en fait une salle aux yeux d'Outlook). Un
     * lieu donné EN PLUS (« bâtiment B ») complète le nom, sans créer un second
     * lieu : `location` et `locations` se correspondent toujours (ressource
     * event), un seul lieu reste le plus sûr à la création comme au PATCH.
     */
    public static function roomLocation(array $room, string $lieu = ''): array
    {
        $nom  = (string)$room['nom'];
        $lieu = trim($lieu);
        if ($lieu !== '' && self::foldText($lieu) !== self::foldText($nom)) {
            $nom .= ' — ' . $lieu;
        }
        return ['displayName' => mb_substr($nom, 0, 1000), 'locationEmailAddress' => (string)$room['adresse']];
    }

    /**
     * Salle ACTUELLE d'un évènement : l'adresse de son lieu, sinon sa seule
     * invitée de type ressource (plusieurs : ce sont peut-être des
     * équipements, on ne devine pas). null sans salle.
     *
     * @return array{nom:string,adresse:string}|null
     */
    public static function currentRoom(array $ev): ?array
    {
        $resources = [];
        foreach ((array)($ev['attendees'] ?? []) as $a) {
            if (is_array($a) && strtolower((string)($a['type'] ?? '')) === 'resource') {
                $addr = trim((string)($a['emailAddress']['address'] ?? ''));
                if ($addr !== '') {
                    $resources[strtolower($addr)] = ['nom' => trim((string)($a['emailAddress']['name'] ?? '')) ?: $addr, 'adresse' => $addr];
                }
            }
        }
        $locAddr = trim((string)($ev['location']['locationEmailAddress'] ?? ''));
        if ($locAddr !== '' && filter_var($locAddr, FILTER_VALIDATE_EMAIL) !== false) {
            return $resources[strtolower($locAddr)]
                ?? ['nom' => trim((string)($ev['location']['displayName'] ?? '')) ?: $locAddr, 'adresse' => $locAddr];
        }
        return count($resources) === 1 ? array_values($resources)[0] : null;
    }

    /** Salle d'un évènement lu et sa réponse, pour lire_evenement_agenda_outlook ('' = aucune). */
    public static function roomStatusLine(array $ev): string
    {
        $room = self::currentRoom($ev);
        if ($room === null) {
            return '';
        }
        $reponse = '';
        foreach ((array)($ev['attendees'] ?? []) as $a) {
            if (is_array($a) && strcasecmp(trim((string)($a['emailAddress']['address'] ?? '')), $room['adresse']) === 0) {
                $reponse = strtolower((string)($a['status']['response'] ?? ''));
            }
        }
        $etat = match ($reponse) {
            'accepted'            => 'réservation acceptée',
            'declined'            => 'réservation REFUSÉE par la salle',
            'tentativelyaccepted' => 'réservation provisoire',
            ''                    => 'lieu seulement : la salle n\'est pas invitée',
            default               => 'réponse de la salle en attente',
        };
        return self::roomLabel($room) . ' (' . $etat . ')';
    }

    /**
     * Réponse de l'annuaire des salles → `{nom, adresse}` valides, sans
     * doublon, rangées par nom.
     *
     * @return list<array{nom:string,adresse:string}>
     */
    public static function roomsFromResponse(array $data): array
    {
        $out = [];
        foreach ((array)($data['value'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $addr = trim((string)($r['address'] ?? ($r['emailAddress'] ?? '')));
            if ($addr === '' || filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $out[strtolower($addr)] ??= [
                'nom'     => mb_substr(trim((string)($r['name'] ?? ($r['displayName'] ?? ''))) ?: $addr, 0, 200),
                'adresse' => $addr,
            ];
        }
        $out = array_values($out);
        usort($out, static fn(array $a, array $b): int => strcasecmp(self::foldText($a['nom']), self::foldText($b['nom'])));
        return $out;
    }

    /**
     * Salles qui répondent à un nom, sans casse ni accents : le nom ou
     * l'adresse EXACTS l'emportent (une seule réponse), sinon toutes celles
     * dont un mot COMMENCE par chaque mot demandé (« everest » trouve « Salle
     * Everest (12 places) » ; « salle 2 » ne trouve pas « Salle 12 »). « salle »,
     * « room » et les articles ne départagent rien : ignorés. Plusieurs
     * réponses = l'appelant nomme les candidates, il ne choisit pas.
     *
     * @param list<array{nom:string,adresse:string}> $rooms
     * @return list<array{nom:string,adresse:string}>
     */
    public static function matchRooms(array $rooms, string $query): array
    {
        $q = self::foldText($query);
        if ($q === '') {
            return $rooms;
        }
        foreach ($rooms as $r) {
            if (self::foldText($r['nom']) === $q || strtolower($r['adresse']) === $q
                || self::foldText((string)strstr($r['adresse'], '@', true)) === $q) {
                return [$r];
            }
        }
        $words = array_values(array_filter(
            preg_split('/[\s,;]+/u', $q) ?: [],
            static fn(string $w): bool => $w !== '' && !in_array($w, ['salle', 'la', 'le', 'de', 'room', 'the'], true)
        ));
        if ($words === []) {
            return $rooms;
        }
        $out = [];
        foreach ($rooms as $r) {
            $hay = self::foldText($r['nom'] . ' ' . $r['adresse']);
            $all = true;
            foreach ($words as $w) {
                if (preg_match('/(^|[^a-z0-9])' . preg_quote($w, '/') . '/u', $hay) !== 1) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** « Salle A (a@x), Salle B (b@x) » — noms ET adresses : le modèle doit pouvoir en passer une. */
    private static function roomNames(array $rooms): string
    {
        return implode(', ', array_map(
            static fn(array $r): string => $r['nom'] . ' (' . $r['adresse'] . ')',
            $rooms
        ));
    }

    /**
     * Réponse de `getSchedule` → disponibilité de chaque adresse sur
     * [from, to) : `occupee` si une plage occupée, provisoire ou « absent »
     * chevauche le créneau (ou si la vue condensée le dit, quand l'agenda ne
     * rend pas le détail) ; `inconnue` pour une adresse en erreur ou absente
     * de la réponse — jamais comptée comme libre.
     *
     * @param list<string> $addresses
     * @return array<string,string> clé = adresse en minuscules
     */
    public static function scheduleStatus(array $data, array $addresses, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];
        foreach ($addresses as $a) {
            $out[strtolower($a)] = 'inconnue';
        }
        foreach ((array)($data['value'] ?? []) as $sched) {
            if (!is_array($sched)) {
                continue;
            }
            $who = strtolower(trim((string)($sched['scheduleId'] ?? '')));
            if ($who === '' || !empty($sched['error'])) {
                continue;
            }
            $busy = false;
            foreach ((array)($sched['scheduleItems'] ?? []) as $item) {
                if (!is_array($item) || !in_array(strtolower((string)($item['status'] ?? 'busy')), ['busy', 'tentative', 'oof'], true)) {
                    continue;
                }
                $s = self::parseBoundary($item['start'] ?? null);
                $e = self::parseBoundary($item['end'] ?? null);
                if ($s !== null && $e !== null && $s < $to && $e > $from) {
                    $busy = true;
                    break;
                }
            }
            // Vue condensée (0 libre, 1 provisoire, 2 occupé, 3 absent, 4 ailleurs),
            // demandée sur le créneau exact : la seule trace quand le détail est caché.
            if (!$busy && preg_match('/[123]/', (string)($sched['availabilityView'] ?? '')) === 1) {
                $busy = true;
            }
            $out[$who] = $busy ? 'occupee' : 'libre';
        }
        return $out;
    }

    /**
     * Modalités communes à la création et à la modification : réunion Teams,
     * rappel, disponibilité affichée, importance, confidentialité. Seul ce qui
     * est demandé est rendu ; un mot inconnu LÈVE (il serait refusé par Graph).
     *
     * `visio` ne sait qu'ajouter (piège 7). Sur un compte sans Teams, Graph
     * crée l'évènement sans réunion en ligne : `createEvent` le constate.
     *
     * @return array<string,mixed>
     */
    public static function optionsPayload(array $args): array
    {
        $out = [];
        if (self::truthy($args['visio'] ?? null)) {
            $out['isOnlineMeeting']       = true;
            $out['onlineMeetingProvider'] = 'teamsForBusiness';
        }
        $rappel = $args['rappel_minutes'] ?? null;
        if (is_numeric($rappel)) {
            $minutes = (int)$rappel;
            if ($minutes < 0) {
                $out['isReminderOn'] = false;
            } else {
                $out['isReminderOn']               = true;
                $out['reminderMinutesBeforeStart'] = min(self::REMINDER_MAX_MIN, $minutes);
            }
        }
        foreach ([
            ['disponibilite', self::SHOW_AS, 'showAs'],
            ['importance', self::IMPORTANCE, 'importance'],
            ['confidentialite', self::SENSITIVITY, 'sensitivity'],
        ] as [$key, $map, $property]) {
            $raw = $args[$key] ?? null;
            $word = is_scalar($raw) && !is_bool($raw) ? str_replace([' ', '-'], '_', self::foldText((string)$raw)) : '';
            if ($word === '') {
                continue;
            }
            if (!isset($map[$word])) {
                throw new \RuntimeException('Valeur de `' . $key . '` inconnue : « ' . mb_substr((string)$raw, 0, 40)
                    . ' » (attendu : ' . implode(', ', array_keys($map)) . ').');
            }
            $out[$property] = $map[$word];
        }
        return $out;
    }

    /**
     * Récurrence d'une création → `patternedRecurrence` de Graph, ou null.
     * Propriétés exigées par type (ressource recurrencePattern) : `weekly` →
     * daysOfWeek + firstDayOfWeek ; `absoluteMonthly` → dayOfMonth ;
     * `absoluteYearly` → dayOfMonth + month. `range.startDate` est la date du
     * début de l'évènement (« Must be the same value as the start property »).
     */
    public static function recurrencePayload(array $args, \DateTimeImmutable $start): ?array
    {
        $raw  = $args['recurrence'] ?? null;
        $type = is_scalar($raw) && !is_bool($raw) ? str_replace([' ', '-'], '_', self::foldText((string)$raw)) : '';
        if ($type === '' || in_array($type, ['aucune', 'non', 'none', 'jamais'], true)) {
            return null;
        }
        if (!in_array($type, self::RECURRENCES, true)) {
            throw new \RuntimeException('Récurrence inconnue : « ' . mb_substr((string)$raw, 0, 40) . ' » (attendu : '
                . implode(', ', self::RECURRENCES) . '). Une récurrence plus fine se règle dans Outlook.');
        }
        $interval = is_numeric($args['recurrence_intervalle'] ?? null)
            ? max(1, min(99, (int)$args['recurrence_intervalle']))
            : 1;
        $dow = (int)$start->format('N');

        if ($type === 'quotidienne') {
            $pattern = ['type' => 'daily', 'interval' => $interval];
        } elseif ($type === 'mensuelle') {
            $pattern = ['type' => 'absoluteMonthly', 'interval' => $interval, 'dayOfMonth' => (int)$start->format('j')];
        } elseif ($type === 'annuelle') {
            $pattern = [
                'type' => 'absoluteYearly', 'interval' => $interval,
                'dayOfMonth' => (int)$start->format('j'), 'month' => (int)$start->format('n'),
            ];
        } else {
            $days = $type === 'jours_ouvres' ? [1, 2, 3, 4, 5] : self::parseDays($args['recurrence_jours'] ?? null);
            if ($days === []) {
                $days = [$dow];
            }
            if (!in_array($dow, $days, true)) {
                throw new \RuntimeException('Le début (' . self::DAYS_FR[$dow] . ') doit tomber un des jours de la '
                    . 'récurrence : choisis un `debut` qui est le premier jour voulu.');
            }
            $pattern = [
                'type'           => 'weekly',
                'interval'       => $type === 'jours_ouvres' ? 1 : $interval,
                'daysOfWeek'     => array_map(static fn(int $d): string => self::GRAPH_DAYS[$d], $days),
                'firstDayOfWeek' => 'monday',
            ];
        }

        $range = ['type' => 'noEnd', 'startDate' => $start->format('Y-m-d')];
        $fin   = trim((string)(is_scalar($args['recurrence_fin'] ?? null) ? $args['recurrence_fin'] : ''));
        if ($fin !== '') {
            if (!self::isDayOnly($fin)) {
                throw new \RuntimeException('`recurrence_fin` attend une date AAAA-MM-JJ.');
            }
            UserCalendarService::parseDateInput($fin, false);
            if ($fin < $range['startDate']) {
                throw new \RuntimeException('La fin de la récurrence précède le début de l\'évènement.');
            }
            $range = ['type' => 'endDate', 'startDate' => $range['startDate'], 'endDate' => $fin];
        } elseif (is_numeric($args['recurrence_occurrences'] ?? null) && (int)$args['recurrence_occurrences'] > 0) {
            $range = [
                'type' => 'numbered', 'startDate' => $range['startDate'],
                'numberOfOccurrences' => min(999, (int)$args['recurrence_occurrences']),
            ];
        }
        return ['pattern' => $pattern, 'range' => $range];
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
            $word = is_scalar($p) ? self::foldText((string)$p) : '';
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
     * L'action écrira-t-elle à des invités ? Une tâche « sans validation » qui
     * n'a pas le droit d'envoyer des emails ne l'exécute pas seule (piège 4).
     * Pour une modification, la réponse vient du serveur (`previent_invites`,
     * posé par le handler après lecture de l'évènement), jamais du modèle.
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
            // Une salle aussi : sa boîte reçoit la demande, et un délégué
            // (accueil, services généraux) la lit souvent.
            return self::parseAddresses($args['invites'] ?? ($args['participants'] ?? null)) !== []
                || self::parseAddresses($args['invites_optionnels'] ?? null) !== []
                || self::parseRoom($args['salle'] ?? null, false) !== null;
        } catch (\RuntimeException) {
            return true;
        }
    }

    /** Lien Outlook d'un évènement : celui de Graph (le domaine dépend du type de compte). */
    public static function eventUrl(array $ev): string
    {
        $link = trim((string)($ev['webLink'] ?? ''));
        return preg_match('#^https://#i', $link) === 1 ? $link : 'https://outlook.office.com/calendar/view/month';
    }

    /** « AAAA-MM-JJ » sans heure ? */
    private static function isDayOnly(string $s): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
    }

    /** Booléen tel que les modèles l'écrivent (true, "true", 1, "oui"). */
    private static function truthy(mixed $v): bool
    {
        if (is_string($v) && in_array(self::foldText($v), ['oui', 'vrai'], true)) {
            return true;
        }
        return is_scalar($v) && filter_var($v, FILTER_VALIDATE_BOOLEAN);
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
        $local = self::localBoundary($node, $allDay, $timeZone);
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
        $local = self::localBoundary($node, $allDay, $timeZone);
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
     * Aperçu HUMAIN d'une création ou d'une modification pour la carte
     * d'approbation (mêmes clés que UserOutlookService::actionPreview + icone).
     *
     * `$ctx` : pour une modification, ce que le handler a lu de l'évènement
     * (`inspectUpdate`) — sans lui, la carte annoncerait un identifiant au lieu
     * de nommer la réunion, et ne saurait pas dire qui sera prévenu.
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
                'action'  => t('Modifier un évènement du calendrier Outlook'),
                'fichier' => (trim((string)($ctx['titre'] ?? '')) ?: t('(sans titre)'))
                    . (trim((string)($ctx['quand'] ?? '')) !== '' ? ' — ' . trim((string)$ctx['quand']) : ''),
                'url'     => preg_match('#^https://#i', (string)($ctx['lien'] ?? '')) === 1 ? (string)$ctx['lien'] : null,
                'impacts' => self::updateImpacts($args, $ctx, $quand),
                'apercu'  => $apercu,
                'icone'   => '/assets/img/outlook-calendar.svg',
            ];
        }

        $titre  = trim((string)($args['titre'] ?? '')) ?: t('(sans titre)');
        $agenda = trim((string)($args['agenda'] ?? ''));
        $lieu   = trim((string)($args['lieu'] ?? ''));
        try {
            $room    = self::parseRoom($args['salle'] ?? null);
            $invites = self::attendeeAddresses(self::attendeeList(
                self::parseAddresses($args['invites'] ?? ($args['participants'] ?? null)),
                self::parseAddresses($args['invites_optionnels'] ?? null),
                '',
                $room
            ), false);
        } catch (\RuntimeException) {
            $room    = null;
            $invites = []; // déjà refusé par le handler : la carte n'existe pas
        }
        if ($room !== null) {
            $lieu = (string)self::roomLocation($room, $lieu)['displayName'];
        }

        $impacts = [
            $agenda !== ''
                ? t('Un évènement sera créé dans votre calendrier Outlook (agenda sélectionné).')
                : t('Un évènement sera créé dans votre calendrier Outlook principal.'),
        ];
        if ($invites !== []) {
            $impacts[] = t('Outlook enverra AUSSITÔT une invitation par email, en votre nom, à : {invites}.', [
                'invites' => implode(', ', $invites),
            ]);
            $impacts[] = t('Une invitation envoyée ne se rattrape pas : vérifiez les adresses avant d\'approuver.');
        } elseif ($room === null) {
            $impacts[] = t('AUCUNE invitation ne sera envoyée : l\'évènement est créé sans participant.');
        } else {
            $impacts[] = t('Aucune personne n\'est invitée : seule la salle recevra la demande.');
        }
        if ($room !== null) {
            $impacts = array_merge($impacts, self::roomImpacts(self::roomLabel($room), $ctx));
        }
        if (self::truthy($args['visio'] ?? null)) {
            $impacts[] = t('Une réunion Teams sera créée ; son lien figurera dans l\'évènement.');
        }
        $recurrence = self::recurrenceLabel($args);
        if ($recurrence !== '') {
            $impacts[] = t('Évènement récurrent : {recurrence}.', ['recurrence' => $recurrence]);
        }
        $impacts[] = t('Vous pourrez le modifier ou le supprimer à tout moment dans Outlook.');

        return [
            'action'  => t('Créer un évènement dans le calendrier Outlook'),
            'fichier' => $titre . ($quand !== '' ? ' — ' . $quand : '') . ($lieu !== '' ? ' — ' . $lieu : ''),
            'url'     => null,
            'impacts' => $impacts,
            'apercu'  => $apercu,
            'icone'   => '/assets/img/outlook-calendar.svg',
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
        if ($quand === '' && !self::truthy($args['journee_entiere'] ?? null)) {
            return '';
        }
        if (self::truthy($args['journee_entiere'] ?? null)) {
            return trim($quand . ' ' . t('(journée entière)'));
        }
        if (UserCalendarService::validTimeZone((string)($args['fuseau'] ?? '')) !== '') {
            $quand .= ' (' . (string)$args['fuseau'] . ')';
        }
        return $quand;
    }

    /** Récurrence demandée, en clair pour la carte ('' = aucune). */
    private static function recurrenceLabel(array $args): string
    {
        $raw  = $args['recurrence'] ?? null;
        $type = is_scalar($raw) && !is_bool($raw) ? str_replace([' ', '-'], '_', self::foldText((string)$raw)) : '';
        $label = match ($type) {
            'quotidienne'  => t('chaque jour'),
            'jours_ouvres' => t('chaque jour ouvré'),
            'hebdomadaire' => t('chaque semaine'),
            'mensuelle'    => t('chaque mois'),
            'annuelle'     => t('chaque année'),
            default        => '',
        };
        if ($label === '') {
            return '';
        }
        $jours = trim((string)(is_scalar($args['recurrence_jours'] ?? null) ? $args['recurrence_jours'] : ''));
        if ($type === 'hebdomadaire' && $jours !== '') {
            $label .= ' (' . $jours . ')';
        }
        $fin = trim((string)(is_scalar($args['recurrence_fin'] ?? null) ? $args['recurrence_fin'] : ''));
        if ($fin !== '') {
            return $label . ' ' . t('jusqu\'au {date}', ['date' => $fin]);
        }
        if (is_numeric($args['recurrence_occurrences'] ?? null) && (int)$args['recurrence_occurrences'] > 0) {
            return $label . ' ' . t('({n} fois)', ['n' => (int)$args['recurrence_occurrences']]);
        }
        return $label . ' ' . t('(sans date de fin)');
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
        $roomOut = is_string($ctx['salle_retiree'] ?? null) ? trim($ctx['salle_retiree']) : '';
        $roomIn  = is_string($ctx['salle'] ?? null) ? trim($ctx['salle']) : '';
        if ($roomOut !== '') {
            $impacts[] = t('La salle {salle} sera libérée.', ['salle' => $roomOut]);
        }
        if ($roomIn !== '') {
            $impacts = array_merge($impacts, self::roomImpacts($roomIn, $ctx));
        } elseif (($ctx['salle_dispo'] ?? '') === 'occupee') {
            // Salle gardée, réunion déplacée sur un créneau où elle est prise.
            $impacts[] = t('La salle {salle} semble déjà réservée sur le nouvel horaire : elle refusera probablement.', [
                'salle' => (string)($ctx['salle_verifiee'] ?? ''),
            ]);
        }
        if (self::truthy($args['visio'] ?? null)) {
            $impacts[] = t('Une réunion Teams sera ajoutée à l\'évènement.');
        }
        if (is_numeric($args['rappel_minutes'] ?? null)) {
            $impacts[] = (int)$args['rappel_minutes'] < 0
                ? t('Le rappel sera désactivé.')
                : t('Rappel : {n} min avant le début.', ['n' => (int)$args['rappel_minutes']]);
        }
        $reglages = [];
        foreach ([
            'disponibilite'   => t('disponibilité'),
            'importance'      => t('importance'),
            'confidentialite' => t('confidentialité'),
        ] as $k => $nom) {
            if ($text($k) !== '') {
                $reglages[] = $nom . ' ' . $text($k);
            }
        }
        if ($reglages !== []) {
            $impacts[] = t('Réglages modifiés : {liste}.', ['liste' => implode(', ', $reglages)]);
        }

        $serie = (string)($ctx['serie'] ?? '');
        if ($serie === 'seriesMaster') {
            $impacts[] = t('C\'est une série : toutes ses occurrences seront modifiées.');
        } elseif ($serie === 'occurrence' || $serie === 'exception') {
            $impacts[] = t('Seule cette occurrence de la série sera modifiée.');
        }

        $invites = array_values(array_filter((array)($ctx['invites'] ?? []), 'is_string'));
        $rooms   = $roomIn !== '' || $roomOut !== '' || trim((string)($ctx['salle_gardee'] ?? '')) !== '';
        if (!empty($ctx['previent']) && $invites === [] && $rooms) {
            // Seule une salle est concernée : pas une personne à qui écrire.
            $impacts[] = t('Outlook transmettra le changement à la salle, qui confirmera ou refusera d\'elle-même.');
        } elseif (!empty($ctx['previent'])) {
            $impacts[] = t('Outlook écrira AUSSITÔT par email, en votre nom, aux invités concernés par ce changement.');
            $impacts[] = t('Un message parti ne se rattrape pas : vérifiez avant d\'approuver.');
        } elseif ($invites === []) {
            $impacts[] = t('Personne ne sera prévenu : cet évènement n\'a pas d\'invité.');
        } else {
            $impacts[] = t('Les invités ne seront pas prévenus : ce réglage ne concerne que votre agenda.');
        }
        return $impacts;
    }

    /**
     * Lignes de la carte pour une salle réservée : ce qui va se passer (la
     * salle répond d'elle-même), puis ce que MIA a vu de son agenda — jamais
     * « réservée » au futur sûr : c'est la salle qui décide (piège 8).
     *
     * @param array{salle_dispo?: string, premiere_occurrence?: bool} $ctx
     * @return list<string>
     */
    private static function roomImpacts(string $label, array $ctx): array
    {
        $out = [t('Salle demandée : {salle}. Elle devient le lieu de la réunion et reçoit la demande de réservation ; elle l\'accepte ou la refuse d\'elle-même, selon son agenda et ses règles.', [
            'salle' => $label,
        ])];
        $suffix = !empty($ctx['premiere_occurrence']) ? ' ' . t('(vérifié sur la première occurrence)') : '';
        $out[] = match ((string)($ctx['salle_dispo'] ?? '')) {
            'libre'   => t('La salle est libre sur ce créneau.') . $suffix,
            'occupee' => t('ATTENTION : la salle semble déjà réservée sur ce créneau — elle refusera probablement.') . $suffix,
            'inconnue' => t('Disponibilité de la salle non vérifiable : son agenda n\'est pas consultable.'),
            default   => t('Disponibilité de la salle non vérifiée.'),
        };
        return $out;
    }
}
