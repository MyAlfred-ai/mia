<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Réunions de l'agenda Google : récurrence selon les modalités de
 * l'utilisateur, visibilité (privée, publique), disponibilité (occupé,
 * disponible), invités (obligatoires, facultatifs, invitation par email ou
 * non), et modification d'un évènement existant.
 *
 * Les gardes visent ce que dit la doc Google (référence Events, guides
 * « Recurring events », « Invite users », « Calendar sharing », relus le
 * 2026-11-18 — cf. l'en-tête de UserCalendarService) :
 *  - récurrence = lignes RFC 5545 (RRULE, EXDATE), `start.timeZone` exigé,
 *    `UNTIL` en date (journée entière) ou en UTC (horodaté) ;
 *  - `sendUpdates` ne décide que de l'email ; les invités sont ajoutés à leur
 *    agenda dans tous les cas ;
 *  - PATCH : un tableau envoyé REMPLACE le tableau (invités recomposés) ;
 *  - propriétés partagées = organisateur seulement.
 *
 * Les appels à l'API sont rejoués sans réseau (sous-classe qui remplace
 * `apiJson`) ; aucun compte Google réel n'a été employé.
 */
final class GoogleCalendarMeetingsTest extends TestCase
{
    private const TZ = 'Europe/Paris';

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    /** Création de base : lundi 23 novembre 2026, 9 h 30, heure de Paris. */
    private static function meeting(array $extra = []): array
    {
        return $extra + ['titre' => 'Point hebdo', 'debut' => '2026-11-23 09:30', 'fin' => '2026-11-23 10:30', 'fuseau' => self::TZ];
    }

    /**
     * Service dont les appels sont servis par `$routes` (préfixe « MÉTHODE url »
     * → réponse ; le plus LONG l'emporte). Chaque appel est noté.
     */
    private static function service(array $routes, string $scope = UserCalendarService::SCOPE_EVENTS): UserCalendarService
    {
        uksort($routes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return new class($routes, $scope) extends UserCalendarService {
            public array $calls = [];

            public function __construct(private array $routes, string $scope)
            {
                parent::__construct(1, 'refresh', 'access', '2099-01-01 00:00:00', $scope, 'moi@ex.fr');
            }

            protected function apiJson(string $method, string $url, ?array $body = null, int $timeout = 60): array
            {
                $this->calls[] = [$method, $url, $body];
                foreach ($this->routes as $prefix => $reponse) {
                    if (str_starts_with($method . ' ' . $url, $prefix)) {
                        return $reponse instanceof \Closure ? $reponse($url, $body) : $reponse;
                    }
                }
                throw new \RuntimeException('Appel inattendu : ' . $method . ' ' . $url);
            }
        };
    }

    private const API = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    /** Une réunion existante que l'utilisateur organise. */
    private static function current(array $extra = []): array
    {
        return $extra + [
            'id'        => 'evt12345',
            'status'    => 'confirmed',
            'summary'   => 'Point hebdo',
            'htmlLink'  => 'https://www.google.com/calendar/event?eid=abc',
            'start'     => ['dateTime' => '2026-11-23T09:30:00+01:00', 'timeZone' => self::TZ],
            'end'       => ['dateTime' => '2026-11-23T10:30:00+01:00', 'timeZone' => self::TZ],
            'organizer' => ['email' => 'moi@ex.fr', 'self' => true],
            'attendees' => [
                ['email' => 'moi@ex.fr', 'organizer' => true, 'self' => true, 'responseStatus' => 'accepted'],
                ['email' => 'paul@ex.fr', 'responseStatus' => 'accepted'],
                ['email' => 'zoe@ex.fr', 'optional' => true, 'responseStatus' => 'needsAction'],
            ],
        ];
    }

    // ===================== Récurrence =====================

    public function testUneSemaineSurDeuxLeLundiEtLeJeudiJusquaUneDate(): void
    {
        $p = UserCalendarService::buildEventPayload(self::meeting([
            'recurrence' => 'hebdomadaire', 'recurrence_intervalle' => 2,
            'recurrence_jours' => 'lundi, jeudi', 'recurrence_fin' => '2026-12-31',
        ]));
        // UNTIL d'un évènement horodaté : la fin du dernier jour, en UTC (RFC 5545).
        $this->assertSame(['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;UNTIL=20261231T225959Z'], $p['recurrence']);
        $this->assertSame(self::TZ, $p['start']['timeZone'], '« For recurring events this field is required ».');
        $this->assertSame(self::TZ, $p['end']['timeZone']);
    }

    public function testChaqueFormeDeRecurrence(): void
    {
        $cas = [
            [['recurrence' => 'quotidienne'], 'RRULE:FREQ=DAILY'],
            [['recurrence' => 'quotidienne', 'recurrence_intervalle' => 3, 'recurrence_occurrences' => 10], 'RRULE:FREQ=DAILY;INTERVAL=3;COUNT=10'],
            [['recurrence' => 'jours_ouvres'], 'RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR'],
            [['recurrence' => 'hebdomadaire'], 'RRULE:FREQ=WEEKLY;BYDAY=MO'],
            [['recurrence' => 'mensuelle'], 'RRULE:FREQ=MONTHLY;BYMONTHDAY=23'],
            [['recurrence' => 'mensuelle', 'recurrence_mensuelle' => 'meme_jour_semaine'], 'RRULE:FREQ=MONTHLY;BYDAY=4MO'],
            [['recurrence' => 'annuelle'], 'RRULE:FREQ=YEARLY'],
            // Alias écrits par les modèles.
            [['recurrence' => 'Chaque semaine'], 'RRULE:FREQ=WEEKLY;BYDAY=MO'],
            [['recurrence' => 'weekly', 'recurrence_jours' => ['Monday', 'Wednesday']], 'RRULE:FREQ=WEEKLY;BYDAY=MO,WE'],
            [['recurrence' => 'Jours ouvrés'], 'RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR'],
        ];
        foreach ($cas as [$args, $rule]) {
            $p = UserCalendarService::buildEventPayload(self::meeting($args));
            $this->assertSame([$rule], $p['recurrence'], json_encode($args));
        }
        // Aucune récurrence : pas de champ, et une précision seule n'est pas ignorée en silence.
        $this->assertArrayNotHasKey('recurrence', UserCalendarService::buildEventPayload(self::meeting(['recurrence' => 'aucune'])));
        $this->assertArrayNotHasKey('recurrence', UserCalendarService::buildEventPayload(self::meeting()));
        $this->expectExceptionMessage('`recurrence_jours` n\'a de sens que pour une réunion récurrente');
        UserCalendarService::buildEventPayload(self::meeting(['recurrence_jours' => 'lundi']));
    }

    public function testLeDernierMardiDuMoisEtLePremierLundi(): void
    {
        // 24 novembre 2026 = 4e ET dernier mardi du mois.
        $p = UserCalendarService::buildEventPayload([
            'titre' => 'Comité', 'debut' => '2026-11-24 14:00', 'fuseau' => self::TZ,
            'recurrence' => 'mensuelle', 'recurrence_mensuelle' => 'dernier_jour_semaine',
        ]);
        $this->assertSame(['RRULE:FREQ=MONTHLY;BYDAY=-1TU'], $p['recurrence']);
        $p = UserCalendarService::buildEventPayload([
            'titre' => 'Comité', 'debut' => '2026-11-02 14:00', 'fuseau' => self::TZ,
            'recurrence' => 'mensuelle', 'recurrence_mensuelle' => 'position', 'recurrence_intervalle' => 3,
        ]);
        $this->assertSame(['RRULE:FREQ=MONTHLY;INTERVAL=3;BYDAY=1MO'], $p['recurrence']);
        $this->assertSame('tous les 3 mois, le premier lundi du mois (sans date de fin)', UserCalendarService::recurrenceLabel([
            'debut' => '2026-11-02 14:00', 'fuseau' => self::TZ,
            'recurrence' => 'mensuelle', 'recurrence_mensuelle' => 'meme_jour_semaine', 'recurrence_intervalle' => 3,
        ]));
    }

    public function testLeDebutDoitEtreUneOccurrenceDeLaRegle(): void
    {
        $refus = [
            // Le début (lundi) n'est pas un jeudi.
            [['recurrence' => 'hebdomadaire', 'recurrence_jours' => 'jeudi'], 'doit tomber un des jours de la récurrence'],
            // Lundi 30 novembre 2026 = 5e lundi : n'existe pas tous les mois.
            [['debut' => '2026-11-30 09:30', 'fin' => '', 'recurrence' => 'mensuelle', 'recurrence_mensuelle' => 'meme_jour_semaine'], '5e lundi'],
            // Le 10 n'est pas le dernier mardi.
            [['debut' => '2026-11-10 09:30', 'fin' => '', 'recurrence' => 'mensuelle', 'recurrence_mensuelle' => 'dernier_jour_semaine'], 'n\'est pas le dernier mardi'],
            // Samedi : pas un jour ouvré.
            [['debut' => '2026-11-28 09:30', 'fin' => '', 'recurrence' => 'jours_ouvres'], 'jour de semaine'],
            [['recurrence' => 'bimensuelle'], 'Récurrence inconnue'],
            [['recurrence' => 'mensuelle', 'recurrence_mensuelle' => 'le 15'], '`recurrence_mensuelle` inconnue'],
            [['recurrence' => 'hebdomadaire', 'recurrence_jours' => 'lundi, funday'], 'Jour de récurrence inconnu'],
            [['recurrence' => 'quotidienne', 'recurrence_fin' => '2026-11-01'], 'précède le début'],
            [['recurrence' => 'quotidienne', 'recurrence_fin' => '31/12/2026'], 'AAAA-MM-JJ'],
        ];
        foreach ($refus as [$args, $message]) {
            try {
                UserCalendarService::buildEventPayload(self::meeting($args));
                $this->fail('Accepté à tort : ' . json_encode($args));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), json_encode($args));
            }
        }
    }

    public function testLesDatesSauteesSuiventLaFormeDuDebut(): void
    {
        $p = UserCalendarService::buildEventPayload(self::meeting([
            'recurrence' => 'hebdomadaire', 'recurrence_exceptions' => '2026-12-28, 2026-12-21',
        ]));
        $this->assertSame([
            'RRULE:FREQ=WEEKLY;BYDAY=MO',
            // Heure LOCALE du début, avec son fuseau ; dates triées.
            'EXDATE;TZID=Europe/Paris:20261221T093000,20261228T093000',
        ], $p['recurrence']);

        // Journée entière : dates seules, UNTIL en date, et le fuseau quand même (série).
        $p = UserCalendarService::buildEventPayload([
            'titre' => 'Astreinte', 'debut' => '2026-11-23', 'fuseau' => self::TZ,
            'recurrence' => 'hebdomadaire', 'recurrence_fin' => '2027-01-31', 'recurrence_exceptions' => ['2026-12-28'],
        ]);
        $this->assertSame(['date' => '2026-11-23', 'timeZone' => self::TZ], $p['start']);
        $this->assertSame(['date' => '2026-11-24', 'timeZone' => self::TZ], $p['end']);
        $this->assertSame(['RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20270131', 'EXDATE;VALUE=DATE:20261228'], $p['recurrence']);

        // Une date qui n'est pas une occurrence : l'utilisateur croirait l'avoir retirée.
        foreach (['2026-12-29', '2026-11-16', '2027-02-01'] as $hors) {
            try {
                UserCalendarService::buildEventPayload(self::meeting([
                    'recurrence' => 'hebdomadaire', 'recurrence_fin' => '2027-01-31', 'recurrence_exceptions' => $hors,
                ]));
                $this->fail('Exception hors série acceptée : ' . $hors);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('n\'est pas une date de cette série', $e->getMessage());
            }
        }
    }

    public function testUneOccurrenceSeCompteEnJoursCivilsMemeAuChangementDHeure(): void
    {
        // Passage à l'heure d'hiver le 25 octobre 2026 : un jour sur deux reste juste.
        $start = new DateTimeImmutable('2026-10-20 09:00', new DateTimeZone(self::TZ));
        $spec  = UserCalendarService::recurrenceSpec(['recurrence' => 'quotidienne', 'recurrence_intervalle' => 2], $start);
        $this->assertTrue(UserCalendarService::occursOn($spec, $start, '2026-10-26'));
        $this->assertFalse(UserCalendarService::occursOn($spec, $start, '2026-10-27'));
        // Une semaine sur deux, le lundi.
        $start = new DateTimeImmutable('2026-11-23 09:30', new DateTimeZone(self::TZ));
        $spec  = UserCalendarService::recurrenceSpec(['recurrence' => 'hebdomadaire', 'recurrence_intervalle' => 2], $start);
        $this->assertTrue(UserCalendarService::occursOn($spec, $start, '2026-12-07'));
        $this->assertFalse(UserCalendarService::occursOn($spec, $start, '2026-11-30'));
        // Chaque année.
        $spec = UserCalendarService::recurrenceSpec(['recurrence' => 'annuelle'], $start);
        $this->assertTrue(UserCalendarService::occursOn($spec, $start, '2027-11-23'));
        $this->assertFalse(UserCalendarService::occursOn($spec, $start, '2027-11-22'));
    }

    public function testLaRecurrenceSeLitEnClairSurLaCarte(): void
    {
        $this->assertSame(
            'toutes les 2 semaines (lundi, jeudi) jusqu\'au 2026-12-31, sauf le 2026-12-21',
            UserCalendarService::recurrenceLabel(self::meeting([
                'recurrence' => 'hebdomadaire', 'recurrence_intervalle' => 2, 'recurrence_jours' => 'jeudi, lundi',
                'recurrence_fin' => '2026-12-31', 'recurrence_exceptions' => '2026-12-21',
            ]))
        );
        $this->assertSame('chaque jour ouvré (10 fois)', UserCalendarService::recurrenceLabel(self::meeting([
            'recurrence' => 'jours_ouvres', 'recurrence_occurrences' => 10,
        ])));
        $this->assertSame('chaque mois, le 31 du mois (les mois plus courts sont sautés) (sans date de fin)', UserCalendarService::recurrenceLabel([
            'debut' => '2026-12-31 18:00', 'fuseau' => self::TZ, 'recurrence' => 'mensuelle',
        ]));
        $this->assertSame('', UserCalendarService::recurrenceLabel(self::meeting()));
    }

    // ===================== Visibilité, disponibilité =====================

    public function testVisibiliteEtDisponibilite(): void
    {
        $p = UserCalendarService::buildEventPayload(self::meeting(['visibilite' => 'privee', 'disponibilite' => 'disponible']));
        $this->assertSame('private', $p['visibility']);
        $this->assertSame('transparent', $p['transparency']);

        $p = UserCalendarService::buildEventPayload(self::meeting(['visibilite' => 'Publique', 'disponibilite' => 'occupé']));
        $this->assertSame('public', $p['visibility']);
        $this->assertSame('opaque', $p['transparency']);

        // Alias : « privé », « confidentiel », « libre », « par défaut ».
        $p = UserCalendarService::buildEventPayload(self::meeting(['visibilite' => 'Privé', 'disponibilite' => 'libre']));
        $this->assertSame(['private', 'transparent'], [$p['visibility'], $p['transparency']]);
        $this->assertSame('private', UserCalendarService::buildEventPayload(self::meeting(['visibilite' => 'confidentiel']))['visibility']);
        $this->assertSame('default', UserCalendarService::buildEventPayload(self::meeting(['visibilite' => 'par défaut']))['visibility']);

        // Rien demandé : rien d'envoyé (le réglage de l'agenda s'applique).
        $p = UserCalendarService::buildEventPayload(self::meeting());
        $this->assertArrayNotHasKey('visibility', $p);
        $this->assertArrayNotHasKey('transparency', $p);

        // Un mot inconnu n'est jamais ignoré : la réunion serait visible là où on la voulait privée.
        $this->expectExceptionMessage('Valeur de `visibilite` inconnue');
        UserCalendarService::buildEventPayload(self::meeting(['visibilite' => 'secrète']));
    }

    public function testLaLectureRendLesReglagesAvecLesMotsDeLOutil(): void
    {
        $this->assertSame('privee', UserCalendarService::settingWord(UserCalendarService::VISIBILITY, 'private', 'par_defaut'));
        $this->assertSame('privee', UserCalendarService::settingWord(UserCalendarService::VISIBILITY, 'confidential', 'par_defaut'));
        $this->assertSame('par_defaut', UserCalendarService::settingWord(UserCalendarService::VISIBILITY, 'default', 'par_defaut'));
        $this->assertSame('disponible', UserCalendarService::settingWord(UserCalendarService::AVAILABILITY, 'transparent', 'occupe'));
        $this->assertSame('occupe', UserCalendarService::settingWord(UserCalendarService::AVAILABILITY, 'inconnue', 'occupe'));
    }

    // ===================== Invités =====================

    public function testInvitesObligatoiresEtFacultatifs(): void
    {
        $p = UserCalendarService::buildEventPayload(self::meeting([
            'invites' => 'Paul Martin <paul@ex.fr>; alice@ex.fr, moi@ex.fr',
            'invites_optionnels' => 'zoe@ex.fr, ALICE@ex.fr',
        ]), 'moi@ex.fr');
        // L'organisateur ne s'invite pas ; un invité cité deux fois reste obligatoire.
        $this->assertSame([
            ['email' => 'paul@ex.fr'],
            ['email' => 'alice@ex.fr'],
            ['email' => 'zoe@ex.fr', 'optional' => true],
        ], $p['attendees']);

        // `participants`, l'ancien nom, est encore lu.
        $p = UserCalendarService::buildEventPayload(self::meeting(['participants' => ['luc@ex.fr']]));
        $this->assertSame([['email' => 'luc@ex.fr']], $p['attendees']);

        $trop = implode(',', array_map(static fn(int $i): string => 'p' . $i . '@ex.fr', range(1, 51)));
        $this->expectExceptionMessage('Trop d\'invités (51)');
        UserCalendarService::buildEventPayload(self::meeting(['invites' => $trop]));
    }

    public function testLInvitationParEmailEstEnvoyeeSaufRefus(): void
    {
        $this->assertTrue(UserCalendarService::wantsInvitations([]));
        $this->assertTrue(UserCalendarService::wantsInvitations(['envoyer_invitations' => true]));
        $this->assertTrue(UserCalendarService::wantsInvitations(['envoyer_invitations' => 'oui']));
        $this->assertFalse(UserCalendarService::wantsInvitations(['envoyer_invitations' => false]));
        $this->assertFalse(UserCalendarService::wantsInvitations(['envoyer_invitations' => 'false']));
        $this->assertFalse(UserCalendarService::wantsInvitations(['envoyer_invitations' => 'non']));
        $this->assertFalse(UserCalendarService::wantsInvitations(['envoyer_invitations' => 0]));
    }

    public function testLaCreationEnvoieLInvitationQuandIlYADesInvites(): void
    {
        $svc = self::service(['POST ' . self::API => ['id' => 'nouveau1', 'summary' => 'Point hebdo',
            'start' => ['dateTime' => '2026-11-23T09:30:00+01:00'], 'htmlLink' => 'https://www.google.com/calendar/event?eid=x']]);
        $r = $svc->createEvent(self::meeting(['invites' => 'paul@ex.fr', 'recurrence' => 'hebdomadaire', 'visibilite' => 'privee']));
        [$method, $url, $body] = $svc->calls[0];
        $this->assertSame('POST', $method);
        $this->assertStringEndsWith('/events?sendUpdates=all', $url);
        $this->assertSame(['RRULE:FREQ=WEEKLY;BYDAY=MO'], $body['recurrence']);
        $this->assertSame('private', $body['visibility']);
        $this->assertSame(['paul@ex.fr'], $r['invites']);
        $this->assertTrue($r['invitations_envoyees']);
        $this->assertTrue($r['recurrent']);
        $this->assertSame('lundi 23/11/2026 09:30', $r['quand']);

        // Sans email demandé : ajoutés quand même (piège 1), aucun email.
        $svc = self::service(['POST ' . self::API => ['id' => 'nouveau2']]);
        $r = $svc->createEvent(self::meeting(['invites' => 'paul@ex.fr', 'envoyer_invitations' => false]));
        $this->assertStringEndsWith('/events?sendUpdates=none', $svc->calls[0][1]);
        $this->assertSame([['email' => 'paul@ex.fr']], $svc->calls[0][2]['attendees']);
        $this->assertFalse($r['invitations_envoyees']);

        // Sans invité : rien à envoyer.
        $svc = self::service(['POST ' . self::API => ['id' => 'nouveau3']]);
        $svc->createEvent(self::meeting());
        $this->assertStringEndsWith('/events?sendUpdates=none', $svc->calls[0][1]);

        // Lecture seule : la création est refusée avant tout appel.
        $svc = self::service([], UserCalendarService::SCOPE_READONLY);
        try {
            $svc->createEvent(self::meeting());
            $this->fail('Création sans droit d\'écriture');
        } catch (RuntimeException $e) {
            $this->assertSame([], $svc->calls);
        }
    }

    public function testUneTacheSansValidationNInviteJamaisSeule(): void
    {
        $creer = UserCalendarService::TOOL_CREATE;
        $this->assertTrue(UserCalendarService::notifiesAttendees($creer, ['invites' => 'a@ex.fr']));
        $this->assertTrue(UserCalendarService::notifiesAttendees($creer, ['invites_optionnels' => 'a@ex.fr']));
        // Même sans email : l'évènement entre dans leur agenda.
        $this->assertTrue(UserCalendarService::notifiesAttendees($creer, ['invites' => 'a@ex.fr', 'envoyer_invitations' => false]));
        $this->assertFalse(UserCalendarService::notifiesAttendees($creer, ['titre' => 'Seul']));
        $this->assertTrue(UserCalendarService::notifiesAttendees(UserCalendarService::TOOL_UPDATE, ['previent_invites' => true]));
        $this->assertFalse(UserCalendarService::notifiesAttendees(UserCalendarService::TOOL_UPDATE, ['ajouter_invites' => 'a@ex.fr']),
            'Pour une modification, seul le serveur le dit (posé par le handler).');
        $this->assertFalse(UserCalendarService::notifiesAttendees('creer_tache', ['invites' => 'a@ex.fr']));
    }

    public function testLaSaisieDesAdressesEstPartageeAvecOutlook(): void
    {
        $this->assertSame(['a@ex.fr', 'b@ex.fr'], UserCalendarService::parseAddresses("A <a@ex.fr>;\nb@ex.fr, a@EX.fr"));
        $this->assertSame(UserCalendarService::parseAddresses('x@ex.fr, y@ex.fr'), UserOutlookCalendarService::parseAddresses('x@ex.fr, y@ex.fr'));
        $this->assertStringContainsString('UserCalendarService::parseAddresses($raw)', self::lire('src/Outlook/UserOutlookCalendarService.php'),
            'Une seule règle de saisie des invités pour les deux agendas.');
        $this->expectExceptionMessage('Adresse d\'invité invalide');
        UserCalendarService::parseAddresses('Paul');
    }

    // ===================== Modification =====================

    public function testAjouterUnePersonneGardeLesInvitesEtLeursReponses(): void
    {
        $plan = UserCalendarService::buildUpdatePayload(['ajouter_invites' => 'luc@ex.fr', 'fuseau' => self::TZ], self::current(), '', 'moi@ex.fr');
        // La liste ENTIÈRE part (un tableau remplace le tableau), réponses comprises.
        $this->assertSame([
            ['email' => 'moi@ex.fr', 'organizer' => true, 'self' => true, 'responseStatus' => 'accepted'],
            ['email' => 'paul@ex.fr', 'responseStatus' => 'accepted'],
            ['email' => 'zoe@ex.fr', 'optional' => true, 'responseStatus' => 'needsAction'],
            ['email' => 'luc@ex.fr'],
        ], $plan['payload']['attendees']);
        $this->assertSame(['attendees'], array_keys($plan['payload']));
        $this->assertSame(['luc@ex.fr'], $plan['ajoutes']);
        $this->assertSame(['paul@ex.fr', 'zoe@ex.fr', 'luc@ex.fr'], $plan['invites']);
        $this->assertTrue($plan['previent']);
        $this->assertTrue($plan['touche_invites']);

        // Facultatif → obligatoire, retrait, sans email.
        $plan = UserCalendarService::buildUpdatePayload([
            'ajouter_invites' => 'zoe@ex.fr', 'retirer_invites' => 'paul@ex.fr', 'envoyer_invitations' => false,
        ], self::current(), '', 'moi@ex.fr');
        $this->assertSame([
            ['email' => 'moi@ex.fr', 'organizer' => true, 'self' => true, 'responseStatus' => 'accepted'],
            ['email' => 'zoe@ex.fr', 'optional' => false, 'responseStatus' => 'needsAction'],
        ], $plan['payload']['attendees']);
        $this->assertSame(['paul@ex.fr'], $plan['retires']);
        $this->assertFalse($plan['previent']);
        $this->assertTrue($plan['touche_invites'], 'Sans email, le changement atteint quand même leur agenda.');

        // Déjà invité au même titre : rien à faire.
        $this->expectExceptionMessage('Aucune modification demandée');
        UserCalendarService::buildUpdatePayload(['ajouter_invites' => 'paul@ex.fr'], self::current(), '', 'moi@ex.fr');
    }

    public function testRetirerQuelquUnQuiNEstPasInviteOuLOrganisateur(): void
    {
        $cas = [
            [['retirer_invites' => 'inconnu@ex.fr'], 'ne fait pas partie des invités'],
            [['retirer_invites' => 'moi@ex.fr'], 'organise cette réunion'],
        ];
        foreach ($cas as [$args, $message]) {
            try {
                UserCalendarService::buildUpdatePayload($args, self::current(), '', 'moi@ex.fr');
                $this->fail('Accepté à tort : ' . json_encode($args));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testPasserUneReunionEnPriveeEtDisponible(): void
    {
        $plan = UserCalendarService::buildUpdatePayload(['visibilite' => 'privee', 'disponibilite' => 'disponible'], self::current(), '', 'moi@ex.fr');
        $this->assertSame(['visibility' => 'private', 'transparency' => 'transparent'], $plan['payload']);
        $this->assertFalse($plan['previent'], 'Un réglage ne justifie pas un email aux invités.');
        $this->assertFalse($plan['touche_invites']);
    }

    public function testQuiNOrganisePasNeRegleQueVisibiliteEtDisponibilite(): void
    {
        $invite = self::current(['organizer' => ['email' => 'chef@ex.fr']]);
        $plan = UserCalendarService::buildUpdatePayload(['disponibilite' => 'disponible'], $invite, '', 'moi@ex.fr');
        $this->assertSame(['transparency' => 'transparent'], $plan['payload']);
        $this->assertFalse($plan['organisateur']);
        $this->assertFalse($plan['previent']);

        try {
            UserCalendarService::buildUpdatePayload(['ajouter_invites' => 'luc@ex.fr'], $invite, '', 'moi@ex.fr');
            $this->fail('Un invité ajouterait des personnes à la réunion d\'un autre.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Seul l\'organisateur (chef@ex.fr)', $e->getMessage());
        }
        // Sauf si l'organisateur a ouvert la réunion aux modifications.
        $plan = UserCalendarService::buildUpdatePayload(['titre' => 'Nouveau'], $invite + ['guestsCanModify' => true], '', 'moi@ex.fr');
        $this->assertSame(['summary' => 'Nouveau'], $plan['payload']);
    }

    public function testHoraireDUnEvenementExistant(): void
    {
        // Début seul : la durée reste (1 h).
        $plan = UserCalendarService::buildUpdatePayload(['debut' => '2026-11-24 14:00', 'fuseau' => self::TZ], self::current(), '', 'moi@ex.fr');
        $this->assertStringStartsWith('2026-11-24T14:00:00+01:00', $plan['payload']['start']['dateTime']);
        $this->assertStringStartsWith('2026-11-24T15:00:00+01:00', $plan['payload']['end']['dateTime']);
        // Forme PATCH : l'autre forme vidée, le fuseau posé.
        $this->assertArrayHasKey('date', $plan['payload']['start']);
        $this->assertNull($plan['payload']['start']['date']);
        $this->assertSame(self::TZ, $plan['payload']['start']['timeZone']);
        $this->assertTrue($plan['previent'], 'Un horaire changé se dit aux invités.');

        // Heure seule : le jour reste.
        $plan = UserCalendarService::buildUpdatePayload(['debut' => '15:00', 'fuseau' => self::TZ], self::current(), '', 'moi@ex.fr');
        $this->assertStringStartsWith('2026-11-23T15:00:00+01:00', $plan['payload']['start']['dateTime']);

        // En journée entière.
        $plan = UserCalendarService::buildUpdatePayload(['journee_entiere' => true, 'fuseau' => self::TZ], self::current(), '', 'moi@ex.fr');
        $this->assertSame(['date' => '2026-11-23', 'dateTime' => null], $plan['payload']['start']);
        $this->assertSame(['date' => '2026-11-24', 'dateTime' => null], $plan['payload']['end']);
    }

    public function testUneSerieNeChangeQueDHeure(): void
    {
        $serie = self::current(['recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO']]);
        $plan = UserCalendarService::buildUpdatePayload(['debut' => '10:00', 'fuseau' => self::TZ], $serie, 'serie', 'moi@ex.fr');
        $this->assertStringStartsWith('2026-11-23T10:00:00+01:00', $plan['payload']['start']['dateTime']);
        $this->assertStringStartsWith('2026-11-23T11:00:00+01:00', $plan['payload']['end']['dateTime']);

        foreach ([
            [['debut' => '2026-11-25 10:00'], 'garde le jour de la première occurrence'],
            [['journee_entiere' => true], 'ne passe pas d\'horaire en journée entière'],
            [['recurrence' => 'quotidienne'], 'se change dans Google Agenda'],
        ] as [$args, $message]) {
            try {
                UserCalendarService::buildUpdatePayload($args + ['fuseau' => self::TZ], $serie, 'serie', 'moi@ex.fr');
                $this->fail('Accepté à tort : ' . json_encode($args));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        // La visibilité d'une réunion récurrente se règle sur la série (Google ignorerait l'occurrence).
        $this->expectExceptionMessage('toute_la_serie: true');
        UserCalendarService::buildUpdatePayload(['visibilite' => 'publique'], self::current(['recurringEventId' => 'serie1']), 'occurrence', 'moi@ex.fr');
    }

    public function testLaModificationViseLaSerieQuandOnLeDemande(): void
    {
        $occurrence = self::current(['id' => 'serie1_20261130T083000Z', 'recurringEventId' => 'serie1']);
        $serie      = self::current(['id' => 'serie1', 'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO']]);
        $svc = self::service([
            'GET ' . self::API . '/serie1_20261130T083000Z' => $occurrence,
            'GET ' . self::API . '/serie1' => $serie,
            'PATCH ' . self::API . '/serie1?' => ['id' => 'serie1', 'summary' => 'Point hebdo', 'start' => $serie['start'], 'htmlLink' => 'https://www.google.com/calendar/event?eid=s'],
        ]);
        $r = $svc->updateEvent([
            'evenement' => 'serie1_20261130T083000Z', 'toute_la_serie' => true,
            'ajouter_invites' => 'luc@ex.fr', 'visibilite' => 'privee', 'fuseau' => self::TZ,
        ]);
        [$method, $url, $body] = $svc->calls[2];
        $this->assertSame('PATCH', $method);
        $this->assertSame(self::API . '/serie1?sendUpdates=all', $url);
        $this->assertSame('private', $body['visibility']);
        $this->assertCount(4, $body['attendees']);
        $this->assertSame('serie', $r['portee']);
        $this->assertSame(['luc@ex.fr'], $r['ajoutes']);
        $this->assertTrue($r['previent']);

        // L'occurrence seule.
        $svc = self::service([
            'GET ' . self::API . '/serie1_20261130T083000Z' => $occurrence,
            'PATCH ' . self::API . '/serie1_20261130T083000Z?' => ['id' => 'serie1_20261130T083000Z'],
        ]);
        $r = $svc->updateEvent(['evenement' => 'serie1_20261130T083000Z', 'disponibilite' => 'disponible']);
        $this->assertSame(self::API . '/serie1_20261130T083000Z?sendUpdates=none', $svc->calls[1][1]);
        $this->assertSame('occurrence', $r['portee']);

        // Un PATCH sans évènement rendu n'est pas annoncé comme fait.
        $svc = self::service([
            'GET ' . self::API . '/evt12345' => self::current(),
            'PATCH ' . self::API . '/evt12345?' => [],
        ]);
        $this->expectExceptionMessage('Modification refusée par Google Agenda');
        $svc->updateEvent(['evenement' => 'evt12345', 'titre' => 'X']);
    }

    // ===================== Cartes d'approbation =====================

    public function testLaCarteDeCreationDitToutCeQuiVaSePasser(): void
    {
        $p = UserCalendarService::actionPreview(UserCalendarService::TOOL_CREATE, self::meeting([
            'invites' => 'paul@ex.fr', 'invites_optionnels' => 'zoe@ex.fr',
            'recurrence' => 'hebdomadaire', 'recurrence_fin' => '2026-12-31',
            'visibilite' => 'privee', 'disponibilite' => 'disponible',
        ]));
        $lignes = implode("\n", $p['impacts']);
        $this->assertStringContainsString('invitation par email, en votre nom, à : paul@ex.fr, zoe@ex.fr (facultatif)', $lignes);
        $this->assertStringContainsString('Une invitation envoyée ne se rattrape pas', $lignes);
        $this->assertStringContainsString('Évènement récurrent : chaque semaine (lundi) jusqu\'au 2026-12-31.', $lignes);
        $this->assertStringContainsString('Visibilité privée', $lignes);
        $this->assertStringContainsString('Vous apparaîtrez disponible', $lignes);
        $this->assertStringContainsString('(Europe/Paris)', $p['fichier']);

        $p = UserCalendarService::actionPreview(UserCalendarService::TOOL_CREATE, self::meeting([
            'invites' => 'paul@ex.fr', 'envoyer_invitations' => false,
        ]));
        $this->assertStringContainsString('SANS email d\'invitation : paul@ex.fr', implode("\n", $p['impacts']));

        $p = UserCalendarService::actionPreview(UserCalendarService::TOOL_CREATE, self::meeting());
        $this->assertStringContainsString('AUCUNE invitation ne sera envoyée', implode("\n", $p['impacts']));
    }

    public function testLaCarteDeModificationNommeLaReunionEtQuiSeraPrevenu(): void
    {
        $ctx = [
            'titre' => 'Point hebdo', 'quand' => 'lundi 23/11/2026 09:30', 'lien' => 'https://www.google.com/calendar/event?eid=abc',
            'portee' => 'serie', 'organisateur' => true, 'previent' => true, 'touche_invites' => true,
            'invites' => ['paul@ex.fr', 'luc@ex.fr'], 'ajoutes' => ['luc@ex.fr'], 'retires' => [],
        ];
        $p = UserCalendarService::actionPreview(UserCalendarService::TOOL_UPDATE, ['ajouter_invites' => 'luc@ex.fr', 'visibilite' => 'privee'], $ctx);
        $this->assertSame('Modifier un évènement Google Agenda', $p['action']);
        $this->assertSame('Point hebdo — lundi 23/11/2026 09:30', $p['fichier']);
        $this->assertSame($ctx['lien'], $p['url']);
        $lignes = implode("\n", $p['impacts']);
        $this->assertStringContainsString('Invités ajoutés : luc@ex.fr.', $lignes);
        $this->assertStringContainsString('Visibilité privée', $lignes);
        $this->assertStringContainsString('toutes ses occurrences seront modifiées', $lignes);
        $this->assertStringContainsString('écrira AUSSITÔT par email', $lignes);

        $p = UserCalendarService::actionPreview(UserCalendarService::TOOL_UPDATE, ['disponibilite' => 'disponible'],
            ['titre' => 'Comité', 'organisateur' => false, 'invites' => ['chef@ex.fr'], 'previent' => false, 'touche_invites' => false]);
        $lignes = implode("\n", $p['impacts']);
        $this->assertStringContainsString('seul votre exemplaire change', $lignes);
        $this->assertStringContainsString('Aucun email ne sera envoyé aux invités pour ce réglage.', $lignes);
    }

    // ===================== Outils du chat =====================

    public function testLesHandlersValidentAvantLaCarteEtLisentLEvenement(): void
    {
        $deposes = [];
        $turn = new AgentTurn(1, 2, [], 1000, static function (array $e): void {},
            function (string $t, array $a, ?array $r) use (&$deposes): array {
                $deposes[] = [$t, $a, $r];
                return ['statut' => 'en_attente_approbation'];
            }, self::TZ);
        $svc = self::service([
            'GET ' . self::API . '/evt12345' => self::current(),
        ]);
        $outils = new GoogleCalendarTools($turn, $svc);
        $noms = array_column($outils->definitions(), 'name');
        $this->assertContains('creer_evenement_agenda', $noms);
        $this->assertContains('modifier_evenement_agenda', $noms);
        $h = $outils->handlers();

        // Erreurs rendues AU MODÈLE, aucune carte.
        $this->assertArrayHasKey('error', $h['creer_evenement_agenda'](['titre' => 'X', 'debut' => '2026-11-23 09:30', 'recurrence' => 'hebdomadaire', 'recurrence_jours' => 'mardi']));
        $this->assertArrayHasKey('error', $h['creer_evenement_agenda'](['titre' => 'X', 'debut' => '2026-11-23 09:30', 'invites' => 'Paul']));
        $this->assertArrayHasKey('error', $h['modifier_evenement_agenda'](['evenement' => 'evt12345', 'retirer_invites' => 'inconnu@ex.fr']));
        $this->assertSame([], $deposes);

        $h['creer_evenement_agenda'](['titre' => 'Point', 'debut' => '2026-11-23 09:30', 'invites' => 'paul@ex.fr', 'recurrence' => 'hebdomadaire']);
        $this->assertSame('creer_evenement_agenda', $deposes[0][0]);
        $this->assertSame(self::TZ, $deposes[0][1]['fuseau'], 'Le fuseau voyage avec l\'action.');

        $h['modifier_evenement_agenda'](['evenement' => 'evt12345', 'ajouter_invites' => 'luc@ex.fr', 'previent_invites' => false]);
        [$outil, $args, $ctx] = $deposes[1];
        $this->assertSame('modifier_evenement_agenda', $outil);
        $this->assertTrue($args['previent_invites'], 'Posé par le serveur, jamais lu du modèle.');
        $this->assertSame('Point hebdo', $ctx['titre']);
        $this->assertSame(['luc@ex.fr'], $ctx['ajoutes']);

        // Connexion en lecture seule : ni création ni modification exposées.
        $lecture = new GoogleCalendarTools($turn, self::service([], UserCalendarService::SCOPE_READONLY));
        $this->assertNotContains('modifier_evenement_agenda', array_column($lecture->definitions(), 'name'));
        $this->assertArrayNotHasKey('modifier_evenement_agenda', $lecture->handlers());
    }

    public function testLesEnumerationsDesOutilsSontCellesDuService(): void
    {
        $turn = new AgentTurn(1, 2, [], 1000, static function (array $e): void {}, static fn(string $t, array $a, ?array $r = null): array => [], self::TZ);
        $defs = [];
        foreach ((new GoogleCalendarTools($turn, self::service([])))->definitions() as $d) {
            $defs[$d['name']] = $d['parameters']['properties'];
        }
        $creer = $defs['creer_evenement_agenda'];
        $this->assertSame(UserCalendarService::RECURRENCES, $creer['recurrence']['enum']);
        $this->assertSame(UserCalendarService::MONTHLY_MODES, $creer['recurrence_mensuelle']['enum']);
        $this->assertSame(array_keys(UserCalendarService::VISIBILITY), $creer['visibilite']['enum']);
        $this->assertSame(array_keys(UserCalendarService::AVAILABILITY), $creer['disponibilite']['enum']);
        foreach (['invites', 'invites_optionnels', 'envoyer_invitations', 'recurrence_exceptions', 'recurrence_occurrences', 'recurrence_fin'] as $k) {
            $this->assertArrayHasKey($k, $creer);
        }
        foreach (['toute_la_serie', 'ajouter_invites', 'retirer_invites', 'visibilite', 'disponibilite', 'envoyer_invitations'] as $k) {
            $this->assertArrayHasKey($k, $defs['modifier_evenement_agenda']);
        }
    }

    // ===================== Câblage =====================

    public function testLeChatExecuteLaCreationEtLaModificationAuClic(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertStringContainsString('in_array((string)$action[\'tool\'], [UserCalendarService::TOOL_CREATE, UserCalendarService::TOOL_UPDATE], true)', $chat);
        $this->assertStringContainsString('$calendar->updateEvent($action[\'args\'])', $chat);
        $this->assertStringContainsString('UserCalendarService::actionPreview($tool, $args, $fileRef ?? [])', $chat);
        $this->assertStringContainsString("'modifier_evenement_agenda' => 'Préparation d\\'une modification d\\'évènement Google Agenda'", $chat);
        $this->assertStringContainsString("'modifier_evenement_agenda' => 'calendar'", $chat);
        // Le filtre de destinataires d'un workflow vaut pour les invités Google.
        $this->assertStringContainsString('UserCalendarService::TOOL_CREATE, UserCalendarService::TOOL_UPDATE', self::lire('src/Agent/SearchScope.php'));
        // Aucune suppression : un seul PATCH, aucun DELETE ni annulation.
        $src = self::lire('src/Calendar/UserCalendarService.php');
        $this->assertSame(0, preg_match("/apiJson\(\s*'DELETE'/", $src));
        $this->assertSame(1, preg_match_all("/apiJson\(\s*'PATCH'/", $src));
        $this->assertStringNotContainsString("'status' => 'cancelled'", $src);
    }

    public function testLesGuidesDisentCeQueSaitFaireLAgendaGoogle(): void
    {
        foreach (['docs/GUIDE-UTILISATEUR.md', 'docs/user/fonctionnalites/connecteurs/organiser-une-reunion-google.md'] as $chemin) {
            $doc = self::lire($chemin);
            foreach (['répét', 'privée', 'disponible', 'invit'] as $mot) {
                $this->assertStringContainsString($mot, $doc, $chemin . ' : ' . $mot);
            }
        }
        $this->assertStringContainsString('agenda-google-reunions', self::lire('docs/NOUVEAUTES.md'));
    }
}
