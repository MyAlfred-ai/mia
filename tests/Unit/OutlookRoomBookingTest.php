<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Calendrier Outlook : réserver une SALLE de réunion (piège 8 de
 * UserOutlookCalendarService).
 *
 * Ce qui est gardé, d'après la doc Graph (Create event, « Setting the location
 * in an event », relue le 2026-11-17) :
 *  - une salle se réserve en l'INVITANT, type `resource`, et en la posant
 *    comme lieu avec son adresse (`locationEmailAddress`) — un lieu en texte
 *    seul ne réserve rien ;
 *  - c'est la salle qui accepte ou refuse : MIA vérifie son agenda AVANT la
 *    carte et ne promet jamais « réservée » ;
 *  - une salle se trouve par son nom dans l'annuaire bêta `findRooms`, qui ne
 *    demande que `User.Read` ; jamais `Place.Read.All` (consentement
 *    administrateur : il ferait refuser toute la connexion) ;
 *  - en modification, `attendees` est la liste ENTIÈRE : l'ancienne salle en
 *    sort, la nouvelle y entre, les personnes restent.
 *
 * Aucun réseau : les appels Graph sont servis par une sous-classe.
 */
final class OutlookRoomBookingTest extends TestCase
{
    private const EVEREST = ['nom' => 'Salle Everest', 'adresse' => 'everest@ex.fr'];

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    /** Annuaire des salles tel que `findRooms` le rend. */
    private static function annuaire(): array
    {
        return ['value' => [
            ['name' => 'Salle Kilimandjaro', 'address' => 'kili@ex.fr'],
            ['name' => 'Salle Everest', 'address' => 'everest@ex.fr'],
            ['name' => 'Salle 12', 'address' => 'salle12@ex.fr'],
            ['name' => 'Salle 2', 'address' => 'salle2@ex.fr'],
            ['name' => 'Salle Mont Blanc (3e étage)', 'address' => 'montblanc@ex.fr'],
            ['name' => 'Salle Montmartre', 'address' => 'montmartre@ex.fr'],
            ['name' => 'Doublon', 'address' => 'EVEREST@ex.fr'],
            ['name' => 'Sans adresse', 'address' => 'pas-une-adresse'],
        ]];
    }

    /**
     * Service dont les appels Graph sont servis par `$routes` (préfixe
     * « MÉTHODE url » → réponse, closure ou exception ; le plus long gagne).
     */
    private static function service(array $routes, string $scope = 'Calendars.Read Calendars.ReadWrite User.Read'): UserOutlookCalendarService
    {
        uksort($routes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return new class($routes, $scope) extends UserOutlookCalendarService {
            public array $calls = [];

            public function __construct(private array $routes, string $scope)
            {
                parent::__construct(1, 'refresh', 'access', null, $scope, 'alex@ex.fr');
            }

            protected function apiJson(string $method, string $url, ?array $body = null, int $timeout = 60): array
            {
                $this->calls[] = [$method, $url, $body];
                foreach ($this->routes as $prefix => $reponse) {
                    if (str_starts_with($method . ' ' . $url, $prefix)) {
                        if ($reponse instanceof \Throwable) {
                            throw $reponse;
                        }
                        return $reponse instanceof \Closure ? $reponse($url, $body) : $reponse;
                    }
                }
                throw new \RuntimeException('Appel inattendu : ' . $method . ' ' . $url);
            }
        };
    }

    private const ROOMS = 'GET https://graph.microsoft.com/beta/me/findRooms';
    private const SCHEDULE = 'POST https://graph.microsoft.com/v1.0/me/calendar/getSchedule';

    /** Réponse `getSchedule` : la salle occupée de 09:00 à 10:00 UTC le 17/08/2026. */
    private static function agendaOccupe(string $qui, bool $occupe = true): array
    {
        return ['value' => [[
            'scheduleId'    => $qui,
            'availabilityView' => $occupe ? '22' : '00',
            'scheduleItems' => $occupe ? [[
                'status' => 'busy',
                'start'  => ['dateTime' => '2026-08-17T07:00:00.0000000', 'timeZone' => 'UTC'],
                'end'    => ['dateTime' => '2026-08-17T08:00:00.0000000', 'timeZone' => 'UTC'],
            ]] : [],
        ]]];
    }

    // ===================== Forme d'une salle =====================

    public function testUneSalleSeLitParSonAdresse(): void
    {
        $this->assertSame(self::EVEREST, UserOutlookCalendarService::parseRoom('Salle Everest <everest@ex.fr>'));
        $this->assertSame(['nom' => 'everest@ex.fr', 'adresse' => 'everest@ex.fr'], UserOutlookCalendarService::parseRoom(' everest@ex.fr '));
        $this->assertSame(self::EVEREST, UserOutlookCalendarService::parseRoom(['nom' => 'Salle Everest', 'adresse' => 'everest@ex.fr']));
        $this->assertNull(UserOutlookCalendarService::parseRoom(''));
        $this->assertNull(UserOutlookCalendarService::parseRoom(null));
        $this->assertNull(UserOutlookCalendarService::parseRoom(true));

        // Un NOM seul n'est pas réservable : la charge utile ne part jamais sans adresse.
        $this->assertNull(UserOutlookCalendarService::parseRoom('Salle Everest', false));
        try {
            UserOutlookCalendarService::parseRoom('Salle Everest');
            $this->fail('Une salle sans adresse a été acceptée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('lister_salles_outlook', $e->getMessage());
        }

        $this->assertSame('Salle Everest <everest@ex.fr>', UserOutlookCalendarService::roomLabel(self::EVEREST));
        $this->assertSame('everest@ex.fr', UserOutlookCalendarService::roomLabel(['nom' => 'everest@ex.fr', 'adresse' => 'everest@ex.fr']));
    }

    // ===================== Création =====================

    /** Doc Graph : invitée `resource` + `locationEmailAddress`. Un lieu seul ne réserve rien. */
    public function testLaSalleEstInviteeCommeRessourceEtDevientLeLieu(): void
    {
        $payload = UserOutlookCalendarService::buildEventPayload([
            'titre'   => 'Comité',
            'debut'   => '2026-08-17 09:00',
            'fuseau'  => 'Europe/Paris',
            'invites' => 'alice@ex.fr, EVEREST@ex.fr',
            'salle'   => 'Salle Everest <everest@ex.fr>',
        ], 'alex@ex.fr');

        $this->assertSame(['displayName' => 'Salle Everest', 'locationEmailAddress' => 'everest@ex.fr'], $payload['location']);
        $this->assertSame([
            ['emailAddress' => ['address' => 'alice@ex.fr'], 'type' => 'required'],
            // Citée aussi parmi les invités : invitée UNE fois, comme salle.
            ['emailAddress' => ['address' => 'everest@ex.fr', 'name' => 'Salle Everest'], 'type' => 'resource'],
        ], $payload['attendees']);

        // Un lieu en plus complète le nom, sans créer un second lieu.
        $avecLieu = UserOutlookCalendarService::buildEventPayload([
            'titre' => 'Comité', 'debut' => '2026-08-17 09:00', 'salle' => 'everest@ex.fr', 'lieu' => 'Bâtiment B',
        ]);
        $this->assertSame(['displayName' => 'everest@ex.fr — Bâtiment B', 'locationEmailAddress' => 'everest@ex.fr'], $avecLieu['location']);
        $this->assertArrayNotHasKey('locations', $avecLieu);

        // Sans salle : rien ne change (lieu en texte, aucune ressource).
        $sans = UserOutlookCalendarService::buildEventPayload(['titre' => 'X', 'debut' => '2026-08-17 09:00', 'lieu' => 'Café']);
        $this->assertSame(['displayName' => 'Café'], $sans['location']);
        $this->assertArrayNotHasKey('attendees', $sans);

        // La salle compte dans le plafond d'invités.
        $cinquante = implode(',', array_map(static fn(int $i): string => "p$i@ex.fr", range(1, 50)));
        $this->expectException(\RuntimeException::class);
        UserOutlookCalendarService::buildEventPayload(['titre' => 'X', 'debut' => '2026-08-17 09:00', 'invites' => $cinquante, 'salle' => 'everest@ex.fr']);
    }

    public function testUneSalleSeuleEstUneEcriturePourUneTacheSansValidation(): void
    {
        $this->assertTrue(UserOutlookCalendarService::notifiesAttendees(UserOutlookCalendarService::TOOL_CREATE, ['salle' => 'everest@ex.fr']));
        $this->assertFalse(UserOutlookCalendarService::notifiesAttendees(UserOutlookCalendarService::TOOL_CREATE, ['salle' => '']));
    }

    // ===================== Modification =====================

    private static function reunion(array $plus = []): array
    {
        return $plus + [
            'id'          => 'AAMkAGI2TG93AAA=evenement00001',
            'subject'     => 'Comité budget',
            'start'       => ['dateTime' => '2026-08-17T07:00:00.0000000', 'timeZone' => 'UTC'],
            'end'         => ['dateTime' => '2026-08-17T08:00:00.0000000', 'timeZone' => 'UTC'],
            'isAllDay'    => false,
            'isOrganizer' => true,
            'location'    => ['displayName' => 'Salle Kilimandjaro', 'locationEmailAddress' => 'kili@ex.fr'],
            'attendees'   => [
                ['type' => 'required', 'emailAddress' => ['address' => 'alice@ex.fr', 'name' => 'Alice']],
                ['type' => 'resource', 'emailAddress' => ['address' => 'kili@ex.fr', 'name' => 'Salle Kilimandjaro'], 'status' => ['response' => 'accepted']],
            ],
        ];
    }

    public function testLaSalleActuelleSeReconnait(): void
    {
        $this->assertSame(['nom' => 'Salle Kilimandjaro', 'adresse' => 'kili@ex.fr'], UserOutlookCalendarService::currentRoom(self::reunion()));
        // Sans adresse de lieu : sa SEULE ressource.
        $this->assertSame('kili@ex.fr', UserOutlookCalendarService::currentRoom(self::reunion(['location' => ['displayName' => 'Kili']]))['adresse']);
        // Deux ressources sans adresse de lieu : peut-être des équipements — on ne devine pas.
        $deux = self::reunion(['location' => [], 'attendees' => [
            ['type' => 'resource', 'emailAddress' => ['address' => 'kili@ex.fr']],
            ['type' => 'resource', 'emailAddress' => ['address' => 'projecteur@ex.fr']],
        ]]);
        $this->assertNull(UserOutlookCalendarService::currentRoom($deux));
        $this->assertNull(UserOutlookCalendarService::currentRoom(['attendees' => []]));

        $this->assertSame('Salle Kilimandjaro <kili@ex.fr> (réservation acceptée)', UserOutlookCalendarService::roomStatusLine(self::reunion()));
        $this->assertSame('', UserOutlookCalendarService::roomStatusLine(['location' => ['displayName' => 'Café']]));
    }

    /** `attendees` est la liste ENTIÈRE : l'ancienne salle sort, la nouvelle entre, Alice reste. */
    public function testChangerDeSalleLibereLAncienne(): void
    {
        $plan = UserOutlookCalendarService::buildUpdatePayload(['salle' => 'Salle Everest <everest@ex.fr>'], self::reunion());

        $this->assertSame([
            ['emailAddress' => ['address' => 'alice@ex.fr', 'name' => 'Alice'], 'type' => 'required'],
            ['emailAddress' => ['address' => 'everest@ex.fr', 'name' => 'Salle Everest'], 'type' => 'resource'],
        ], $plan['payload']['attendees']);
        $this->assertSame(['displayName' => 'Salle Everest', 'locationEmailAddress' => 'everest@ex.fr'], $plan['payload']['location']);
        $this->assertSame('Salle Everest <everest@ex.fr>', $plan['salle']);
        $this->assertSame('Salle Kilimandjaro <kili@ex.fr>', $plan['salle_retiree']);
        $this->assertSame(['alice@ex.fr'], $plan['invites'], 'Les invités de la carte sont des PERSONNES.');
        $this->assertSame([], $plan['ajoutes']);
        $this->assertTrue($plan['previent']);
    }

    public function testLibererLaSalle(): void
    {
        $plan = UserOutlookCalendarService::buildUpdatePayload(['retirer_salle' => 'true'], self::reunion());
        $this->assertSame([['emailAddress' => ['address' => 'alice@ex.fr', 'name' => 'Alice'], 'type' => 'required']], $plan['payload']['attendees']);
        $this->assertSame(['displayName' => ''], $plan['payload']['location']);
        $this->assertSame('Salle Kilimandjaro <kili@ex.fr>', $plan['salle_retiree']);

        // … avec un nouveau lieu en texte.
        $cafe = UserOutlookCalendarService::buildUpdatePayload(['retirer_salle' => true, 'lieu' => 'Café du coin'], self::reunion());
        $this->assertSame(['displayName' => 'Café du coin'], $cafe['payload']['location']);

        // Retirer la salle par son adresse, c'est la libérer (le lieu suit).
        $parAdresse = UserOutlookCalendarService::buildUpdatePayload(['retirer_invites' => 'KILI@ex.fr'], self::reunion());
        $this->assertSame(['displayName' => ''], $parAdresse['payload']['location']);
        $this->assertSame([], $parAdresse['retires'], 'La salle n\'est pas une personne retirée.');
        $this->assertSame('Salle Kilimandjaro <kili@ex.fr>', $parAdresse['salle_retiree']);
    }

    /** Un nouveau lieu ne fait pas oublier la salle à Outlook. */
    public function testUnNouveauLieuResteRattacheALaSalle(): void
    {
        $plan = UserOutlookCalendarService::buildUpdatePayload(['lieu' => 'Bâtiment B'], self::reunion());
        $this->assertSame(['displayName' => 'Salle Kilimandjaro — Bâtiment B', 'locationEmailAddress' => 'kili@ex.fr'], $plan['payload']['location']);
        $this->assertArrayNotHasKey('attendees', $plan['payload']);
        $this->assertSame('Salle Kilimandjaro <kili@ex.fr>', $plan['salle_gardee']);

        // Sans salle : comportement d'avant (lieu en texte).
        $sans = UserOutlookCalendarService::buildUpdatePayload(['lieu' => 'Café'], self::reunion(['location' => [], 'attendees' => []]));
        $this->assertSame(['displayName' => 'Café'], $sans['payload']['location']);
    }

    public function testCeQuUneModificationDeSalleRefuse(): void
    {
        $cas = [
            [['salle' => 'everest@ex.fr', 'retirer_salle' => true], self::reunion(), 'pas les deux'],
            [['retirer_salle' => true], self::reunion(['location' => [], 'attendees' => []]), 'pas de salle'],
            [['salle' => 'everest@ex.fr'], self::reunion(['isOrganizer' => false]), 'organisateur'],
            [['retirer_salle' => true], self::reunion(['isOrganizer' => false]), 'organisateur'],
            // Déjà dans cette salle : rien à envoyer.
            [['salle' => 'KILI@ex.fr'], self::reunion(), 'Aucune modification'],
            // Un nom non résolu ne part jamais.
            [['salle' => 'Salle Everest'], self::reunion(), 'sans adresse'],
        ];
        foreach ($cas as [$args, $evenement, $mot]) {
            try {
                UserOutlookCalendarService::buildUpdatePayload($args, $evenement);
                $this->fail('Accepté à tort : ' . json_encode($args));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($mot, $e->getMessage());
            }
        }
        // Ajouter la salle comme « invitée » ne la change pas en personne.
        try {
            UserOutlookCalendarService::buildUpdatePayload(['ajouter_invites' => 'kili@ex.fr'], self::reunion());
            $this->fail('La salle a été réinvitée comme une personne.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Aucune modification', $e->getMessage());
        }
    }

    // ===================== Annuaire et disponibilité (formes pures) =====================

    public function testLAnnuaireEstNettoyeEtRange(): void
    {
        $rooms = UserOutlookCalendarService::roomsFromResponse(self::annuaire());
        $this->assertSame(['salle12@ex.fr', 'salle2@ex.fr', 'everest@ex.fr', 'kili@ex.fr', 'montblanc@ex.fr', 'montmartre@ex.fr'], array_column($rooms, 'adresse'));
        $this->assertSame([], UserOutlookCalendarService::roomsFromResponse([]));
    }

    public function testUnNomTrouveSaSalleSansDeviner(): void
    {
        $rooms = UserOutlookCalendarService::roomsFromResponse(self::annuaire());
        $noms  = static fn(array $r): array => array_column($r, 'nom');

        $this->assertSame(['Salle Everest'], $noms(UserOutlookCalendarService::matchRooms($rooms, 'salle everest')));
        $this->assertSame(['Salle Everest'], $noms(UserOutlookCalendarService::matchRooms($rooms, 'EVEREST')));
        $this->assertSame(['Salle Mont Blanc (3e étage)'], $noms(UserOutlookCalendarService::matchRooms($rooms, 'mont blanc')));
        $this->assertSame(['Salle Mont Blanc (3e étage)'], $noms(UserOutlookCalendarService::matchRooms($rooms, 'etage')));
        // « Salle 2 » exact ; un mot doit COMMENCER un mot : « 2 » ne trouve pas « 12 ».
        $this->assertSame(['Salle 2'], $noms(UserOutlookCalendarService::matchRooms($rooms, 'salle 2')));
        // Une demande bavarde ne trouve rien plutôt qu'une mauvaise salle.
        $this->assertSame([], UserOutlookCalendarService::matchRooms($rooms, 'salle 2 svp'));
        $this->assertSame([], UserOutlookCalendarService::matchRooms($rooms, 'zanzibar'));
        // Plusieurs candidates : l'appelant les nomme, il ne choisit pas.
        $this->assertCount(2, UserOutlookCalendarService::matchRooms($rooms, 'mont'));
    }

    public function testLaDisponibiliteNeCompteJamaisUnInconnuCommeLibre(): void
    {
        $from = new \DateTimeImmutable('2026-08-17T07:30:00Z');
        $to   = new \DateTimeImmutable('2026-08-17T08:30:00Z');
        $adr  = ['everest@ex.fr', 'kili@ex.fr', 'absente@ex.fr', 'erreur@ex.fr'];

        $etat = UserOutlookCalendarService::scheduleStatus(['value' => [
            self::agendaOccupe('Everest@ex.fr')['value'][0],     // 07:00-08:00 chevauche 07:30-08:30
            ['scheduleId' => 'kili@ex.fr', 'availabilityView' => '0000', 'scheduleItems' => [[
                'status' => 'busy',
                'start'  => ['dateTime' => '2026-08-17T08:30:00', 'timeZone' => 'UTC'],   // juste après : libre
                'end'    => ['dateTime' => '2026-08-17T09:00:00', 'timeZone' => 'UTC'],
            ]]],
            ['scheduleId' => 'erreur@ex.fr', 'error' => ['message' => 'not found']],
        ]], $adr, $from, $to);

        $this->assertSame([
            'everest@ex.fr' => 'occupee',
            'kili@ex.fr'    => 'libre',
            'absente@ex.fr' => 'inconnue',
            'erreur@ex.fr'  => 'inconnue',
        ], $etat);

        // Détail caché par la salle : la vue condensée suffit.
        $vue = UserOutlookCalendarService::scheduleStatus(['value' => [
            ['scheduleId' => 'everest@ex.fr', 'availabilityView' => '0200', 'scheduleItems' => []],
        ]], ['everest@ex.fr'], $from, $to);
        $this->assertSame('occupee', $vue['everest@ex.fr']);
        // « libre » provisoire ou « ailleurs » ne bloquent pas, mais l'absence (3) oui.
        $libre = UserOutlookCalendarService::scheduleStatus(['value' => [
            ['scheduleId' => 'everest@ex.fr', 'availabilityView' => '0004', 'scheduleItems' => []],
        ]], ['everest@ex.fr'], $from, $to);
        $this->assertSame('libre', $libre['everest@ex.fr']);
    }

    // ===================== Service (Graph remplacé) =====================

    public function testUneSalleSeTrouveParSonNomDansLAnnuaire(): void
    {
        $svc = self::service([self::ROOMS => self::annuaire()]);
        $this->assertSame(self::EVEREST, $svc->resolveRoom('everest'));
        // L'annuaire n'est lu qu'une fois par tour.
        $svc->resolveRoom('kilimandjaro');
        $this->assertCount(1, $svc->calls);
        // Une adresse seule reçoit le nom de l'annuaire.
        $this->assertSame(self::EVEREST, $svc->resolveRoom('Everest@ex.fr'));

        foreach ([['mont', 'Plusieurs salles'], ['zanzibar', 'Salle Everest (everest@ex.fr)']] as [$demande, $attendu]) {
            try {
                $svc->resolveRoom($demande);
                $this->fail("« $demande » a été résolue.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($attendu, $e->getMessage());
            }
        }
    }

    /** L'API bêta en panne n'empêche pas de réserver une salle par son adresse. */
    public function testAnnuaireEnPanneLAdresseSuffit(): void
    {
        $svc = self::service([self::ROOMS => new \RuntimeException('Agenda ou évènement introuvable dans votre calendrier Outlook')]);
        $this->assertSame('salle@ex.fr', $svc->resolveRoom('salle@ex.fr')['adresse']);
        try {
            $svc->resolveRoom('Everest');
            $this->fail('Nom résolu sans annuaire.');
        } catch (\RuntimeException $e) {
            // Jamais « évènement introuvable » : le modèle doit demander l'adresse.
            $this->assertSame(UserOutlookCalendarService::MSG_ROOMS_UNAVAILABLE, $e->getMessage());
        }

        // Une connexion expirée, elle, se dit telle quelle.
        $expire = self::service([self::ROOMS => new \RuntimeException(UserOutlookCalendarService::MSG_RECONNECT)]);
        $this->expectExceptionMessage(UserOutlookCalendarService::MSG_RECONNECT);
        $expire->listRooms();
    }

    public function testLesSallesLibresSurUnCreneauViennentDAbord(): void
    {
        $body = null;
        $svc  = self::service([
            self::ROOMS    => self::annuaire(),
            self::SCHEDULE => function (string $url, ?array $b) use (&$body): array {
                $body = $b;
                return ['value' => [
                    self::agendaOccupe('everest@ex.fr')['value'][0],
                    ['scheduleId' => 'kili@ex.fr', 'availabilityView' => '00', 'scheduleItems' => []],
                ]];
            },
        ]);
        $svc->setTimeZone('Europe/Paris');
        $res = $svc->searchRooms('salle', '2026-08-17 09:00', '2026-08-17 10:00');

        // Créneau envoyé en UTC (piège 1), une seule requête pour toutes les salles.
        $this->assertSame('2026-08-17T07:00:00', $body['startTime']['dateTime']);
        $this->assertSame('UTC', $body['startTime']['timeZone']);
        $this->assertContains('everest@ex.fr', $body['schedules']);
        $this->assertLessThanOrEqual(UserOutlookCalendarService::ROOMS_AVAILABILITY_MAX, count($body['schedules']));

        $this->assertSame('libre', $res['salles'][0]['disponibilite']);
        $this->assertSame('Salle Kilimandjaro', $res['salles'][0]['nom']);
        $this->assertSame('occupee', end($res['salles'])['disponibilite']);
        $this->assertSame(6, $res['total']);
        $this->assertStringContainsString('17/08/2026 09:00', $res['creneau']);

        // Sans créneau : la liste seule, aucun appel d'agenda.
        $simple = self::service([self::ROOMS => self::annuaire()]);
        $this->assertArrayNotHasKey('disponibilite', $simple->searchRooms('everest', '', '')['salles'][0]);
        $this->assertCount(1, $simple->calls);

        $this->expectException(\RuntimeException::class);
        $simple->searchRooms('', '2026-08-17', '');
    }

    /** Le contrôle d'avant la carte ne bloque jamais : une panne se dit « inconnue ». */
    public function testLeControleDeLaSalleNeBloqueJamais(): void
    {
        $bounds = UserOutlookCalendarService::timedBounds(
            new \DateTimeImmutable('2026-08-17 09:00', new \DateTimeZone('Europe/Paris')),
            new \DateTimeImmutable('2026-08-17 10:00', new \DateTimeZone('Europe/Paris')),
            'Europe/Paris'
        );
        $occupe = self::service([self::SCHEDULE => self::agendaOccupe('everest@ex.fr')]);
        $this->assertSame(['salle_dispo' => 'occupee', 'salle_verifiee' => 'Salle Everest <everest@ex.fr>'], $occupe->roomCheck(self::EVEREST, $bounds));

        $panne = self::service([self::SCHEDULE => new \RuntimeException('HTTP 500')]);
        $this->assertSame('inconnue', $panne->roomCheck(self::EVEREST, $bounds, true)['salle_dispo']);
        $this->assertTrue($panne->roomCheck(self::EVEREST, $bounds, true)['premiere_occurrence']);

        // Ni salle, ni journée entière : rien à vérifier, aucun appel.
        $rien = self::service([]);
        $this->assertSame([], $rien->roomCheck(null, $bounds));
        $this->assertSame([], $rien->roomCheck(self::EVEREST, ['isAllDay' => true] + $bounds));
        $this->assertSame([], $rien->calls);
    }

    public function testCreerUneReunionDansUneSalle(): void
    {
        $posted = null;
        $svc = self::service([
            'POST https://graph.microsoft.com/v1.0/me/calendar/events' => function (string $url, ?array $b) use (&$posted): array {
                $posted = $b;
                return ['id' => 'AAMkNouvelEvenement0001', 'subject' => 'Comité', 'start' => $b['start'], 'isAllDay' => false, 'webLink' => 'https://outlook.office.com/x'];
            },
        ]);
        $res = $svc->createEvent([
            'titre' => 'Comité', 'debut' => '2026-08-17 09:00', 'fuseau' => 'Europe/Paris',
            'invites' => 'alice@ex.fr', 'salle' => 'Salle Everest <everest@ex.fr>',
        ]);
        $this->assertSame('resource', end($posted['attendees'])['type']);
        $this->assertSame('everest@ex.fr', $posted['location']['locationEmailAddress']);
        $this->assertSame(['alice@ex.fr'], $res['invites'], 'Les invitations annoncées sont celles des PERSONNES.');
        $this->assertSame('Salle Everest <everest@ex.fr>', $res['salle']);
    }

    public function testUneModificationVerifieLaNouvelleSalleSeulement(): void
    {
        $evenement = self::reunion();
        $routes = [
            'GET https://graph.microsoft.com/v1.0/me/events/' => $evenement,
            self::SCHEDULE => self::agendaOccupe('everest@ex.fr'),
        ];
        $ctx = self::service($routes)->inspectUpdate(['evenement' => $evenement['id'], 'salle' => 'Salle Everest <everest@ex.fr>']);
        $this->assertSame('occupee', $ctx['salle_dispo']);
        $this->assertSame('Salle Kilimandjaro <kili@ex.fr>', $ctx['salle_retiree']);

        // Salle gardée, horaire qui chevauche l'ancien : pas de vérification
        // (elle se verrait occupée par cette même réunion).
        $svc = self::service([
            'GET https://graph.microsoft.com/v1.0/me/events/' => $evenement,
            self::SCHEDULE => self::agendaOccupe('kili@ex.fr'),
        ]);
        $svc->inspectUpdate(['evenement' => $evenement['id'], 'debut' => '2026-08-17 09:30', 'fuseau' => 'Europe/Paris']);
        $this->assertSame(['GET'], array_column($svc->calls, 0));

        // … mais déplacée ailleurs, elle est revérifiée.
        $loin = self::service([
            'GET https://graph.microsoft.com/v1.0/me/events/' => $evenement,
            self::SCHEDULE => ['value' => [['scheduleId' => 'kili@ex.fr', 'availabilityView' => '0000', 'scheduleItems' => []]]],
        ]);
        $ctx = $loin->inspectUpdate(['evenement' => $evenement['id'], 'debut' => '2026-08-18 14:00', 'fuseau' => 'Europe/Paris']);
        $this->assertSame('libre', $ctx['salle_dispo']);
        $this->assertSame('Salle Kilimandjaro <kili@ex.fr>', $ctx['salle_verifiee']);
    }

    // ===================== Outil du chat (handler réel) =====================

    public function testLeHandlerResoutLaSalleAvantLaCarte(): void
    {
        $queued = [];
        $turn = new AgentTurn(1, 2, [], 1000, static function (array $e): void {},
            function (string $tool, array $args, ?array $ctx) use (&$queued): array {
                $queued[] = [$tool, $args, $ctx];
                return ['statut' => 'en_attente_approbation'];
            }, 'Europe/Paris');
        $svc = self::service([
            self::ROOMS    => self::annuaire(),
            self::SCHEDULE => self::agendaOccupe('everest@ex.fr'),
        ]);
        $handlers = (new OutlookCalendarTools($turn, $svc))->handlers();

        $res = $handlers[UserOutlookCalendarService::TOOL_CREATE]([
            'titre' => 'Comité', 'debut' => '2026-08-17 09:00', 'salle' => 'everest',
        ]);
        // La carte porte la salle RÉSOLUE : l'exécution ne dépend plus de l'annuaire.
        $this->assertSame('Salle Everest <everest@ex.fr>', $queued[0][1]['salle']);
        $this->assertSame('occupee', $queued[0][2]['salle_dispo']);
        $this->assertSame(UserOutlookCalendarService::ROOM_BUSY_NOTE, $res['avertissement']);

        // Une salle introuvable revient au modèle, sans carte.
        $err = $handlers[UserOutlookCalendarService::TOOL_CREATE](['titre' => 'X', 'debut' => '2026-08-17 09:00', 'salle' => 'zanzibar']);
        $this->assertArrayHasKey('error', $err);
        $this->assertCount(1, $queued);

        // L'outil de lecture des salles.
        $liste = $handlers[UserOutlookCalendarService::TOOL_ROOMS](['requete' => 'kili']);
        $this->assertSame('kili@ex.fr', $liste['salles'][0]['adresse']);
    }

    // ===================== Carte d'approbation =====================

    public function testLaCarteNeDitJamaisReserveeDOffice(): void
    {
        $apercu = UserOutlookCalendarService::actionPreview(UserOutlookCalendarService::TOOL_CREATE, [
            'titre' => 'Comité', 'debut' => '2026-08-17 09:00', 'salle' => 'Salle Everest <everest@ex.fr>',
        ], ['salle_dispo' => 'occupee']);
        $lignes = implode(' ', $apercu['impacts']);
        $this->assertStringContainsString('Salle Everest', $apercu['fichier']);
        $this->assertStringContainsString('seule la salle', $lignes);
        $this->assertStringContainsString('accepte ou la refuse', $lignes);
        $this->assertStringContainsString('ATTENTION', $lignes);
        $this->assertStringNotContainsString('AUCUNE invitation', $lignes);

        $libre = implode(' ', UserOutlookCalendarService::actionPreview(UserOutlookCalendarService::TOOL_CREATE, [
            'titre' => 'Comité', 'debut' => '2026-08-17 09:00', 'invites' => 'alice@ex.fr', 'salle' => 'everest@ex.fr',
        ], ['salle_dispo' => 'libre'])['impacts']);
        $this->assertStringContainsString('alice@ex.fr.', $libre, 'Seules les personnes sont listées comme invitées.');
        $this->assertStringContainsString('libre sur ce créneau', $libre);

        $modif = implode(' ', UserOutlookCalendarService::actionPreview(UserOutlookCalendarService::TOOL_UPDATE, ['salle' => 'everest@ex.fr'], [
            'titre' => 'Comité', 'previent' => true, 'invites' => [], 'salle' => 'Salle Everest <everest@ex.fr>',
            'salle_retiree' => 'Salle Kilimandjaro <kili@ex.fr>', 'salle_dispo' => 'libre',
        ])['impacts']);
        $this->assertStringContainsString('Salle Kilimandjaro <kili@ex.fr> sera libérée', $modif);
        $this->assertStringContainsString('transmettra le changement à la salle', $modif);
    }

    // ===================== Câblage =====================

    public function testLaSalleEstBrancheeDeBoutEnBout(): void
    {
        // Aucun scope de plus : `findRooms` vit de User.Read, `getSchedule` de
        // Calendars.Read. Place.Read.All exigerait un administrateur et ferait
        // refuser TOUTE la connexion là où il n'a pas consenti.
        $this->assertContains('User.Read', MicrosoftCalendarOAuth::SCOPES);
        $this->assertNotContains('Place.Read.All', MicrosoftCalendarOAuth::SCOPES);
        $this->assertNotContains('Place.ReadWrite.All', MicrosoftCalendarOAuth::SCOPES);

        // L'API bêta, une seule adresse, en lecture seule.
        $service = self::lire('src/Outlook/UserOutlookCalendarService.php');
        $this->assertSame(1, substr_count($service, 'graph.microsoft.com/beta'));
        $this->assertStringContainsString("\$this->apiJson('GET', self::ROOMS_URL)", $service);

        $outils = self::lire('src/Agent/Tools/OutlookCalendarTools.php');
        foreach (["'salle'", "'retirer_salle'", 'UserOutlookCalendarService::TOOL_ROOMS', 'self::withResolvedRoom($args, $msCalendarService)'] as $bout) {
            $this->assertStringContainsString($bout, $outils, "$bout absent des outils.");
        }
        $this->assertSame(2, substr_count($outils, 'self::withResolvedRoom($args, $msCalendarService)'), 'Création ET modification résolvent la salle.');

        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertStringContainsString("'lister_salles_outlook'    => ['t' => 'mscalendar_rooms'", $chat);
        $this->assertStringContainsString("'mscalendar_rooms'          => 'Recherche de salles de réunion (Outlook)'", $chat);
        $this->assertStringContainsString("'mscalendar_rooms'          => 'calendar'", $chat);
        $this->assertStringContainsString('Demande de réservation envoyée à la salle {salle}', $chat);
        $this->assertStringContainsString("mscalendar_rooms:          t('Recherche de salles de réunion…')", self::lire('public/assets/js/chat.js'));

        // Workflow : la salle suit le filtre de destinataires (sa boîte reçoit la demande).
        $this->assertStringContainsString("'ajouter_invites_optionnels', 'salle'] as \$k", self::lire('src/Agent/SearchScope.php'));
        $this->assertArrayHasKey('salle', WorkflowContentView::HEADER_ARGS);
        $scope = SearchScope::fromFilters(WorkflowFilters::normalize(['recipients' => '@ex.fr']));
        $this->assertNotNull($scope->writeRefusal(UserOutlookCalendarService::TOOL_CREATE, ['salle' => 'Salle X <x@ailleurs.com>']));
        $this->assertNull($scope->writeRefusal(UserOutlookCalendarService::TOOL_CREATE, ['salle' => 'Salle Everest <everest@ex.fr>']));
    }
}
