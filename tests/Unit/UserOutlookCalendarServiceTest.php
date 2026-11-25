<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Connecteur Calendrier Outlook : logique PURE du service (aucun réseau, aucune
 * base) et câblage du connecteur dans l'application.
 *
 * Les gardes visent les pièges RÉELS de Microsoft Graph, tous documentés en tête
 * de UserOutlookCalendarService :
 *  - `calendarView` interprète en UTC un horodatage SANS décalage → la fenêtre
 *    de recherche glisse d'une à deux heures, silencieusement ;
 *  - la réponse sépare `dateTime` (sans décalage) de `timeZone` → parser le seul
 *    horodatage affiche toutes les heures fausses, sans erreur ;
 *  - une fin de journée entière est EXCLUSIVE → l'afficher brute annonce un jour
 *    de trop ;
 *  - `Calendars.Read` est un PRÉFIXE de `Calendars.ReadWrite` ET de
 *    `Calendars.ReadBasic`, qui ne rend que des créneaux occupé/libre ;
 *  - aucun équivalent de `sendUpdates=none` : ajouter un participant l'INVITE,
 *    donc la carte d'approbation nomme chaque invité.
 */
final class UserOutlookCalendarServiceTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    /** Exécute un test dans un fuseau donné, puis restaure celui du processus. */
    private static function dansFuseau(string $tz, callable $fn): void
    {
        $avant = date_default_timezone_get();
        date_default_timezone_set($tz);
        try {
            $fn();
        } finally {
            date_default_timezone_set($avant);
        }
    }

    // ===================== Scopes =====================

    public function testScopeGrantedCompareLesJetonsExactement(): void
    {
        $this->assertTrue(UserOutlookCalendarService::scopeGranted('Calendars.Read User.Read', 'Calendars.Read'));
        $this->assertTrue(UserOutlookCalendarService::scopeGranted(
            'https://graph.microsoft.com/Calendars.ReadWrite https://graph.microsoft.com/User.Read',
            'Calendars.ReadWrite'
        ));

        // LE piège : Calendars.Read est un préfixe des deux autres, et
        // Calendars.ReadBasic ne rend PAS un agenda lisible.
        $this->assertFalse(UserOutlookCalendarService::scopeGranted('Calendars.ReadBasic', 'Calendars.Read'));
        $this->assertFalse(UserOutlookCalendarService::scopeGranted('Calendars.Read', 'Calendars.ReadWrite'));
        $this->assertFalse(UserOutlookCalendarService::scopeGranted('offline_access User.Read', 'Calendars.Read'));
    }

    /**
     * La comparaison de scopes vit en UN exemplaire, sur le socle Microsoft :
     * deux implémentations divergentes afficheraient « Lecture ✓ » sur une
     * connexion incapable de lire quoi que ce soit.
     */
    public function testLaComparaisonDeScopesEstDeclareeUneSeuleFois(): void
    {
        foreach ([UserOutlookCalendarService::class, UserOutlookService::class,
                  MicrosoftUserApiService::class] as $classe) {
            $this->assertSame(
                MicrosoftUserApiService::class,
                (new \ReflectionMethod($classe, 'scopeGranted'))->getDeclaringClass()->getName(),
                "$classe redéclare scopeGranted() : le piège de préfixe se rejouerait à moitié."
            );
        }
    }

    // ===================== Agendas =====================

    public function testAgendaParDefautEstUnCheminPasUnIdentifiant(): void
    {
        // Graph n'a pas d'id littéral pour l'agenda principal : c'est le chemin
        // /me/calendar. Un « primary » à la Google partirait en segment d'URL.
        foreach (['', '  ', 'primary', 'Principal', 'défaut', 'default'] as $entree) {
            $this->assertSame('', UserOutlookCalendarService::normalizeCalendarId($entree));
        }
        $this->assertStringEndsWith('/me/calendar', UserOutlookCalendarService::calendarPath(''));

        $id = 'AAMkAGI2NGVhZDNlLTk1ODUtNDg5MC1iZmZmLTBlMzZkYjkyNmE0Nw==';
        $this->assertSame($id, UserOutlookCalendarService::normalizeCalendarId(' ' . $id . ' '));
        $this->assertStringContainsString('/me/calendars/' . rawurlencode($id), UserOutlookCalendarService::calendarPath($id));
    }

    public function testIdentifiantDAgendaInvalideRefuse(): void
    {
        foreach (['court', 'abc def ghi jkl mno', '../../etc/passwd-aaaaaaaaaa'] as $mauvais) {
            try {
                UserOutlookCalendarService::normalizeCalendarId($mauvais);
                $this->fail('Identifiant accepté à tort : « ' . $mauvais . ' »');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('lister_agendas_outlook', $e->getMessage());
            }
        }
    }

    public function testIdentifiantDEvenement(): void
    {
        $id = 'AAMkAGI2NGVhZDNlLTk1ODUtNDg5MC1iZmZmLTBlMzZkYjkyNmE0NwBGAAAAAAA=';
        $this->assertSame($id, UserOutlookCalendarService::assertEventId(' ' . $id . ' '));

        foreach (['', 'court', '../../etc/passwd-aaaaaaaaaa'] as $mauvais) {
            try {
                UserOutlookCalendarService::assertEventId($mauvais);
                $this->fail('Identifiant accepté à tort : « ' . $mauvais . ' »');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('rechercher_agenda_outlook', $e->getMessage());
            }
        }
    }

    // ===================== Fenêtre de recherche =====================

    /**
     * LE piège n° 1 : sans décalage horaire, Graph lit les bornes en UTC et la
     * fenêtre glisse — l'évènement de 01h00 « aujourd'hui » disparaît.
     */
    public function testLesBornesDeFenetrePortentToujoursLeurDecalage(): void
    {
        self::dansFuseau('Europe/Paris', function (): void {
            $tz   = new \DateTimeZone('Europe/Paris');
            $from = new \DateTimeImmutable('2026-08-17 00:00:00', $tz);
            $to   = new \DateTimeImmutable('2026-08-24 00:00:00', $tz);
            $url  = UserOutlookCalendarService::viewUrl('', $from, $to, 10);

            $this->assertStringContainsString('/me/calendar/calendarView?', $url);
            $this->assertStringContainsString(rawurlencode('2026-08-17T00:00:00+02:00'), $url);
            $this->assertStringContainsString(rawurlencode('2026-08-24T00:00:00+02:00'), $url);
            $this->assertStringContainsString('$top=10', $url);
            $this->assertStringContainsString(rawurlencode('start/dateTime'), $url);
            // `$search` n'existe pas sur calendarView : ne jamais le tenter.
            $this->assertStringNotContainsString('$search', $url);
            $this->assertStringNotContainsString('$filter', $url);
        });
    }

    public function testLeNombreDeLignesEstBorne(): void
    {
        $tz   = new \DateTimeZone('UTC');
        $from = new \DateTimeImmutable('2026-08-17', $tz);
        $to   = new \DateTimeImmutable('2026-08-18', $tz);

        $this->assertStringContainsString('$top=1', UserOutlookCalendarService::viewUrl('', $from, $to, 0));
        $this->assertStringContainsString('$top=100', UserOutlookCalendarService::viewUrl('', $from, $to, 9999));
    }

    // ===================== Bornes d'évènement =====================

    /**
     * LE piège n° 2 : `dateTime` n'a AUCUN décalage, le fuseau vit à côté. Lire
     * le seul horodatage afficherait 07:00 au lieu de 09:00, sans une erreur.
     */
    public function testLeCoupleHeureEtFuseauEstLuEntier(): void
    {
        self::dansFuseau('Europe/Paris', function (): void {
            $this->assertSame(
                'lundi 17/08/2026 09:00',
                UserOutlookCalendarService::formatEventBoundary(
                    ['dateTime' => '2026-08-17T07:00:00.0000000', 'timeZone' => 'UTC']
                )
            );
            // Fuseau nommé autrement que UTC : respecté tel quel.
            $this->assertSame(
                'lundi 17/08/2026 09:00',
                UserOutlookCalendarService::formatEventBoundary(
                    ['dateTime' => '2026-08-17T09:00:00', 'timeZone' => 'Europe/Paris']
                )
            );
        });
    }

    public function testFuseauInconnuRetombeSurUtcEtNonSurCeluiDuServeur(): void
    {
        // PHP ne connaît pas les identifiants Windows. UTC est l'hypothèse
        // documentée par Graph ; prendre le fuseau du serveur inventerait un
        // décalage.
        $this->assertSame('UTC', UserOutlookCalendarService::graphTimeZone('Romance Standard Time'));
        $this->assertSame('UTC', UserOutlookCalendarService::graphTimeZone(''));
        $this->assertSame('Europe/Paris', UserOutlookCalendarService::graphTimeZone('Europe/Paris'));
    }

    public function testHorodatageIllisibleRenduTelQuel(): void
    {
        $this->assertSame('n/a', UserOutlookCalendarService::formatEventBoundary(['dateTime' => 'n/a']));
        $this->assertSame('', UserOutlookCalendarService::formatEventBoundary(null));
        $this->assertNull(UserOutlookCalendarService::parseBoundary(['dateTime' => '  ']));
    }

    /**
     * LE piège n° 3 : la fin d'une journée entière est EXCLUSIVE chez Graph.
     * Rendue brute, elle annonce un jour de trop.
     */
    public function testLaFinDUneJourneeEntiereEstRamenéeAuDernierJourOccupe(): void
    {
        self::dansFuseau('Europe/Paris', function (): void {
            $resume = UserOutlookCalendarService::summarizeEvent([
                'id'       => 'AAA',
                'subject'  => 'Congés',
                'isAllDay' => true,
                'start'    => ['dateTime' => '2026-08-17T00:00:00.0000000', 'timeZone' => 'Europe/Paris'],
                'end'      => ['dateTime' => '2026-08-19T00:00:00.0000000', 'timeZone' => 'Europe/Paris'],
            ], '');

            $this->assertSame('lundi 17/08/2026 (journée entière)', $resume['debut']);
            $this->assertSame('mardi 18/08/2026 (journée entière)', $resume['fin']);
            $this->assertTrue($resume['journee_entiere']);
        });
    }

    public function testResumeDEvenement(): void
    {
        self::dansFuseau('Europe/Paris', function (): void {
            $resume = UserOutlookCalendarService::summarizeEvent([
                'id'          => 'AAA',
                'subject'     => '  Comité budget ',
                'location'    => ['displayName' => 'Salle 3'],
                'isCancelled' => true,
                'start'       => ['dateTime' => '2026-08-17T07:00:00.0000000', 'timeZone' => 'UTC'],
                'end'         => ['dateTime' => '2026-08-17T08:00:00.0000000', 'timeZone' => 'UTC'],
            ], 'CAL1');

            $this->assertSame('AAA', $resume['id']);
            $this->assertSame('CAL1', $resume['agenda']);
            $this->assertSame('Comité budget', $resume['titre']);
            $this->assertSame('Salle 3', $resume['lieu']);
            $this->assertSame('lundi 17/08/2026 09:00', $resume['debut']);
            $this->assertSame('lundi 17/08/2026 10:00', $resume['fin']);
            $this->assertTrue($resume['annule']);
            $this->assertFalse($resume['journee_entiere']);
            // Sans titre : jamais une ligne vide illisible.
            $this->assertSame('(sans titre)', UserOutlookCalendarService::summarizeEvent(['id' => 'B'], '')['titre']);
        });
    }

    // ===================== Filtre texte (appliqué en PHP) =====================

    public function testLeFiltreTexteIgnoreCasseEtAccents(): void
    {
        $ev = [
            'subject'     => 'Réunion budget',
            'bodyPreview' => 'Ordre du jour',
            'location'    => ['displayName' => 'Salle Éclair'],
            'attendees'   => [['emailAddress' => ['name' => 'Alice', 'address' => 'alice@ex.fr']]],
            'organizer'   => ['emailAddress' => ['address' => 'chef@ex.fr']],
        ];

        $this->assertTrue(UserOutlookCalendarService::matchesQuery($ev, 'reunion'));
        $this->assertTrue(UserOutlookCalendarService::matchesQuery($ev, 'ÉCLAIR'));
        $this->assertTrue(UserOutlookCalendarService::matchesQuery($ev, 'alice@ex.fr'));
        $this->assertTrue(UserOutlookCalendarService::matchesQuery($ev, 'chef@ex.fr'));
        $this->assertTrue(UserOutlookCalendarService::matchesQuery($ev, 'ordre du jour'));
        $this->assertFalse(UserOutlookCalendarService::matchesQuery($ev, 'comité'));
        // Filtre vide = tout passe (la fenêtre de dates fait seule le tri).
        $this->assertTrue(UserOutlookCalendarService::matchesQuery($ev, '   '));
    }

    // ===================== Création d'évènement =====================

    public function testEvenementHorodateEnvoieHeureLocaleEtFuseauNomme(): void
    {
        self::dansFuseau('Europe/Paris', function (): void {
            $payload = UserOutlookCalendarService::buildEventPayload([
                'titre'       => 'Point hebdo',
                'debut'       => '2026-08-17 09:00',
                'fin'         => '2026-08-17 10:30',
                'lieu'        => 'Salle 3',
                'description' => 'Ordre du jour',
            ]);

            $this->assertSame('Point hebdo', $payload['subject']);
            // Heure « nue » + fuseau nommé : un RFC3339 dans dateTime serait refusé.
            $this->assertSame('2026-08-17T09:00:00', $payload['start']['dateTime']);
            $this->assertSame('Europe/Paris', $payload['start']['timeZone']);
            $this->assertSame('2026-08-17T10:30:00', $payload['end']['dateTime']);
            $this->assertFalse($payload['isAllDay']);
            $this->assertSame('Salle 3', $payload['location']['displayName']);
            // Texte brut : jamais de HTML fabriqué depuis le texte du modèle.
            $this->assertSame('Text', $payload['body']['contentType']);
        });
    }

    public function testFinAbsenteVautUneHeure(): void
    {
        self::dansFuseau('Europe/Paris', function (): void {
            $payload = UserOutlookCalendarService::buildEventPayload([
                'titre' => 'Appel',
                'debut' => '2026-08-17 09:00',
            ]);
            $this->assertSame('2026-08-17T10:00:00', $payload['end']['dateTime']);
        });
    }

    public function testJourneeEntiereMinuitEtFinExclusive(): void
    {
        self::dansFuseau('Europe/Paris', function (): void {
            $payload = UserOutlookCalendarService::buildEventPayload([
                'titre' => 'Congés',
                'debut' => '2026-08-17',
                'fin'   => '2026-08-18',
            ]);

            $this->assertTrue($payload['isAllDay']);
            // Les deux sont exigés par Graph : minuit, et fin au lendemain.
            $this->assertSame('2026-08-17T00:00:00', $payload['start']['dateTime']);
            $this->assertSame('2026-08-19T00:00:00', $payload['end']['dateTime']);
        });
    }

    public function testJourneeEntiereRefuseUneHeure(): void
    {
        $this->expectException(\RuntimeException::class);
        UserOutlookCalendarService::buildEventPayload([
            'titre'           => 'Congés',
            'debut'           => '2026-08-17 09:00',
            'journee_entiere' => true,
        ]);
    }

    public function testEntreesInvalidesRefusees(): void
    {
        foreach ([
            ['debut' => '2026-08-17 09:00'],                                  // titre absent
            ['titre' => 'X'],                                                 // début absent
            ['titre' => 'X', 'debut' => '2026-08-17 10:00', 'fin' => '2026-08-17 09:00'], // fin avant début
            ['titre' => 'X', 'debut' => 'demain'],                            // date illisible
        ] as $args) {
            try {
                UserOutlookCalendarService::buildEventPayload($args);
                $this->fail('Arguments acceptés à tort : ' . json_encode($args));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ===================== Invités, réunion Teams, modalités =====================

    /**
     * LE piège n° 4 : Graph n'a AUCUN équivalent de `sendUpdates=none` — un
     * évènement créé avec des invités leur envoie l'invitation. Les invités
     * sont donc acceptés (c'est la demande), et c'est la CARTE qui prévient.
     */
    public function testLesInvitesPartentDansLaFormeDeGraph(): void
    {
        $payload = UserOutlookCalendarService::buildEventPayload([
            'titre'              => 'Comité',
            'debut'              => '2026-08-17 09:00',
            'invites'            => 'Alice Martin <alice@ex.fr>; bob@ex.fr, ALICE@ex.fr',
            'invites_optionnels' => ['carla@ex.fr', 'bob@ex.fr', 'moi@ex.fr'],
        ], 'Moi@ex.fr');

        $this->assertSame([
            ['emailAddress' => ['address' => 'alice@ex.fr'], 'type' => 'required'],
            ['emailAddress' => ['address' => 'bob@ex.fr'], 'type' => 'required'],
            // Cité deux fois : obligatoire l'emporte ; l'organisateur ne s'invite pas.
            ['emailAddress' => ['address' => 'carla@ex.fr'], 'type' => 'optional'],
        ], $payload['attendees']);

        // L'ancien nom du paramètre reste lu : l'ignorer laisserait croire des invités conviés.
        $ancien = UserOutlookCalendarService::buildEventPayload([
            'titre' => 'Comité', 'debut' => '2026-08-17 09:00', 'participants' => ['alice@ex.fr'],
        ]);
        $this->assertSame('alice@ex.fr', $ancien['attendees'][0]['emailAddress']['address']);
    }

    public function testUneAdresseDInviteInvalideEstRefuseeAvantLaCarte(): void
    {
        foreach (['Paul', 'paul@', 'alice@ex.fr, Paul Durand'] as $invites) {
            try {
                UserOutlookCalendarService::buildEventPayload([
                    'titre' => 'Réunion', 'debut' => '2026-08-17 09:00', 'invites' => $invites,
                ]);
                $this->fail('Adresse acceptée à tort : ' . $invites);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Adresse d\'invité invalide', $e->getMessage());
            }
        }
        $trop = implode(',', array_map(static fn(int $i): string => "p$i@ex.fr", range(1, UserOutlookCalendarService::MAX_ATTENDEES + 1)));
        $this->expectException(\RuntimeException::class);
        UserOutlookCalendarService::buildEventPayload(['titre' => 'X', 'debut' => '2026-08-17 09:00', 'invites' => $trop]);
    }

    public function testReunionTeamsRappelEtReglages(): void
    {
        $payload = UserOutlookCalendarService::buildEventPayload([
            'titre' => 'Point', 'debut' => '2026-08-17 09:00',
            'visio' => 'true', 'rappel_minutes' => 30,
            'disponibilite' => 'Provisoire', 'importance' => 'haute', 'confidentialite' => 'privée',
        ]);
        // Valeurs de la doc Graph (ressource event).
        $this->assertTrue($payload['isOnlineMeeting']);
        $this->assertSame('teamsForBusiness', $payload['onlineMeetingProvider']);
        $this->assertTrue($payload['isReminderOn']);
        $this->assertSame(30, $payload['reminderMinutesBeforeStart']);
        $this->assertSame('tentative', $payload['showAs']);
        $this->assertSame('high', $payload['importance']);
        $this->assertSame('private', $payload['sensitivity']);

        $this->assertSame(['isReminderOn' => false], UserOutlookCalendarService::optionsPayload(['rappel_minutes' => -1]));
        // Rien demandé = rien envoyé (un PATCH ne doit porter que ce qui change).
        $this->assertSame([], UserOutlookCalendarService::optionsPayload(['visio' => false, 'disponibilite' => '']));

        $this->expectException(\RuntimeException::class);
        UserOutlookCalendarService::optionsPayload(['disponibilite' => 'en vacances']);
    }

    public function testRecurrenceDansLaFormeDeGraph(): void
    {
        self::dansFuseau('Europe/Paris', function (): void {
            // 2026-08-17 est un lundi.
            $hebdo = UserOutlookCalendarService::buildEventPayload([
                'titre' => 'Point', 'debut' => '2026-08-17 09:00',
                'recurrence' => 'hebdomadaire', 'recurrence_jours' => 'lundi, Jeudi',
                'recurrence_intervalle' => 2, 'recurrence_fin' => '2026-12-31',
            ])['recurrence'];
            // `weekly` exige daysOfWeek ET firstDayOfWeek (ressource recurrencePattern).
            $this->assertSame(
                ['type' => 'weekly', 'interval' => 2, 'daysOfWeek' => ['monday', 'thursday'], 'firstDayOfWeek' => 'monday'],
                $hebdo['pattern']
            );
            // startDate = date du début de l'évènement (ressource recurrenceRange).
            $this->assertSame(['type' => 'endDate', 'startDate' => '2026-08-17', 'endDate' => '2026-12-31'], $hebdo['range']);

            $base = ['titre' => 'Point', 'debut' => '2026-08-17 09:00'];
            $this->assertSame(
                ['pattern' => ['type' => 'daily', 'interval' => 1], 'range' => ['type' => 'numbered', 'startDate' => '2026-08-17', 'numberOfOccurrences' => 5]],
                UserOutlookCalendarService::buildEventPayload($base + ['recurrence' => 'quotidienne', 'recurrence_occurrences' => 5])['recurrence']
            );
            $this->assertSame(
                ['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => 17],
                UserOutlookCalendarService::buildEventPayload($base + ['recurrence' => 'mensuelle'])['recurrence']['pattern']
            );
            $annuelle = UserOutlookCalendarService::buildEventPayload($base + ['recurrence' => 'annuelle'])['recurrence'];
            $this->assertSame(['type' => 'absoluteYearly', 'interval' => 1, 'dayOfMonth' => 17, 'month' => 8], $annuelle['pattern']);
            $this->assertSame(['type' => 'noEnd', 'startDate' => '2026-08-17'], $annuelle['range']);
            $ouvres = UserOutlookCalendarService::buildEventPayload($base + ['recurrence' => 'jours_ouvres'])['recurrence']['pattern'];
            $this->assertSame(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $ouvres['daysOfWeek']);

            $this->assertArrayNotHasKey('recurrence', UserOutlookCalendarService::buildEventPayload($base + ['recurrence' => '']));

            foreach ([
                ['recurrence' => 'hebdomadaire', 'recurrence_jours' => 'mardi'],   // le début (lundi) n'est pas un jour de la série
                ['recurrence' => 'quotidienne', 'recurrence_fin' => '2026-08-01'], // fin avant le début
                ['recurrence' => 'tous les trois jours ouvrés'],                   // inconnue
                ['recurrence' => 'hebdomadaire', 'recurrence_jours' => 'lundi, jeudredi'],
            ] as $args) {
                try {
                    UserOutlookCalendarService::buildEventPayload($base + $args);
                    $this->fail('Récurrence acceptée à tort : ' . json_encode($args));
                } catch (\RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
    }

    /**
     * Doc Graph (dateTimeTimeZone) : seuls les noms Windows et une liste
     * FERMÉE de noms IANA sont garantis en écriture. Un fuseau de navigateur
     * hors liste est remplacé par un fuseau de la liste qui a les mêmes heures.
     */
    public function testLeFuseauEcritEstUnFuseauQueGraphDocumente(): void
    {
        $quand = new \DateTimeImmutable('2026-08-17 09:00', new \DateTimeZone('Europe/Brussels'));
        $this->assertSame('Europe/Paris', UserOutlookCalendarService::graphWriteZone('Europe/Paris', $quand));
        $this->assertSame('UTC', UserOutlookCalendarService::graphWriteZone('UTC', $quand));

        $zone = UserOutlookCalendarService::graphWriteZone('Europe/Brussels', $quand);
        $this->assertNotSame('Europe/Brussels', $zone);
        $this->assertNotSame('', $zone);
        // Mêmes heures : l'heure locale envoyée désigne le même instant.
        $this->assertSame(
            $quand->getTimestamp(),
            (new \DateTimeImmutable('2026-08-17 09:00', new \DateTimeZone($zone)))->getTimestamp()
        );
        $this->assertSame(
            (new \DateTimeImmutable('2026-12-17 09:00', new \DateTimeZone('Europe/Brussels')))->getTimestamp(),
            (new \DateTimeImmutable('2026-12-17 09:00', new \DateTimeZone($zone)))->getTimestamp()
        );

        $payload = UserOutlookCalendarService::buildEventPayload([
            'titre' => 'Point', 'debut' => '2026-08-17 09:00', 'fuseau' => 'Europe/Brussels',
        ]);
        $this->assertSame('2026-08-17T09:00:00', $payload['start']['dateTime']);
        $this->assertSame($zone, $payload['start']['timeZone']);

        // Aucun équivalent documenté : l'instant part en UTC (toujours accepté).
        $this->assertSame('', UserOutlookCalendarService::graphWriteZone('Pas/UnFuseau', $quand));
        $kiev = new \DateTimeImmutable('2026-08-17 09:00', new \DateTimeZone('Asia/Kathmandu'));
        $this->assertSame('Asia/Kathmandu', UserOutlookCalendarService::graphWriteZone('Asia/Kathmandu', $kiev));
    }

    // ===================== Modification =====================

    /** Évènement tel que Graph le rend (bornes en UTC, sans décalage). */
    private static function evenement(array $plus = []): array
    {
        return $plus + [
            'id'          => 'AAMkAGI2TG93AAA=evenement00001',
            'subject'     => 'Comité budget',
            'start'       => ['dateTime' => '2026-08-17T07:00:00.0000000', 'timeZone' => 'UTC'],
            'end'         => ['dateTime' => '2026-08-17T08:30:00.0000000', 'timeZone' => 'UTC'],
            'isAllDay'    => false,
            'isOrganizer' => true,
            'attendees'   => [
                ['type' => 'required', 'emailAddress' => ['address' => 'Alice@ex.fr', 'name' => 'Alice Martin'], 'status' => ['response' => 'accepted']],
                ['type' => 'optional', 'emailAddress' => ['address' => 'bob@ex.fr', 'name' => 'Bob']],
            ],
        ];
    }

    public function testUneModificationNEnvoieQueCeQuiChange(): void
    {
        $plan = UserOutlookCalendarService::buildUpdatePayload(
            ['evenement' => 'x', 'titre' => 'Comité budget 2027', 'fuseau' => 'Europe/Paris', 'visio' => false, 'lieu' => ''],
            self::evenement()
        );
        $this->assertSame(['subject' => 'Comité budget 2027'], $plan['payload']);
        // Des invités, et le titre change : Outlook leur écrit.
        $this->assertTrue($plan['previent']);

        // Réglage personnel : personne n'est prévenu.
        $perso = UserOutlookCalendarService::buildUpdatePayload(['rappel_minutes' => 10, 'disponibilite' => 'libre'], self::evenement());
        $this->assertSame(['isReminderOn' => true, 'reminderMinutesBeforeStart' => 10, 'showAs' => 'free'], $perso['payload']);
        $this->assertFalse($perso['previent']);

        $this->expectException(\RuntimeException::class);
        UserOutlookCalendarService::buildUpdatePayload(['evenement' => 'x'], self::evenement());
    }

    public function testDeplacerGardeLaDureeEtLHeure(): void
    {
        // 07:00 → 08:30 UTC = 09:00 → 10:30 à Paris (été).
        $args = ['fuseau' => 'Europe/Paris'];

        $debut = UserOutlookCalendarService::buildUpdatePayload($args + ['debut' => '2026-08-18 14:00'], self::evenement())['payload'];
        $this->assertFalse($debut['isAllDay']);
        $this->assertSame(['dateTime' => '2026-08-18T14:00:00', 'timeZone' => 'Europe/Paris'], $debut['start']);
        $this->assertSame('2026-08-18T15:30:00', $debut['end']['dateTime'], 'La durée (1 h 30) est gardée.');

        // Une date seule déplace le JOUR, l'heure reste.
        $jour = UserOutlookCalendarService::buildUpdatePayload($args + ['debut' => '2026-08-20'], self::evenement())['payload'];
        $this->assertSame('2026-08-20T09:00:00', $jour['start']['dateTime']);
        $this->assertSame('2026-08-20T10:30:00', $jour['end']['dateTime']);

        // La fin seule garde le début.
        $fin = UserOutlookCalendarService::buildUpdatePayload($args + ['fin' => '2026-08-17 12:00'], self::evenement())['payload'];
        $this->assertSame('2026-08-17T09:00:00', $fin['start']['dateTime']);
        $this->assertSame('2026-08-17T12:00:00', $fin['end']['dateTime']);

        // Passage en journée entière : minuit, fin exclusive.
        $jourEntier = UserOutlookCalendarService::buildUpdatePayload($args + ['journee_entiere' => true], self::evenement())['payload'];
        $this->assertTrue($jourEntier['isAllDay']);
        $this->assertSame('2026-08-17T00:00:00', $jourEntier['start']['dateTime']);
        $this->assertSame('2026-08-18T00:00:00', $jourEntier['end']['dateTime']);

        // Journée entière de deux jours déplacée : deux jours gardés.
        $conges = self::evenement([
            'isAllDay' => true,
            'start'    => ['dateTime' => '2026-08-17T00:00:00.0000000', 'timeZone' => 'UTC'],
            'end'      => ['dateTime' => '2026-08-19T00:00:00.0000000', 'timeZone' => 'UTC'],
            'attendees' => [],
        ]);
        $deplace = UserOutlookCalendarService::buildUpdatePayload(['debut' => '2026-09-01', 'fuseau' => 'America/New_York'], $conges)['payload'];
        $this->assertTrue($deplace['isAllDay']);
        $this->assertSame('2026-09-01T00:00:00', $deplace['start']['dateTime']);
        $this->assertSame('2026-09-03T00:00:00', $deplace['end']['dateTime']);

        try {
            UserOutlookCalendarService::buildUpdatePayload($args + ['fin' => '2026-08-17 08:00'], self::evenement());
            $this->fail('Fin avant le début acceptée.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Piège n° 5 : `attendees` est la liste ENTIÈRE. Envoyer les seuls
     * nouveaux retirerait les autres de la réunion.
     */
    public function testLesInvitesSontRecomposesSurLaListeActuelle(): void
    {
        $plan = UserOutlookCalendarService::buildUpdatePayload([
            'ajouter_invites' => 'carla@ex.fr, alice@ex.fr, bob@ex.fr',
            'retirer_invites' => '',
        ], self::evenement());

        $this->assertSame([
            // Nom et adresse d'origine conservés ; déjà obligatoire : inchangée.
            ['emailAddress' => ['address' => 'Alice@ex.fr', 'name' => 'Alice Martin'], 'type' => 'required'],
            // Facultatif → obligatoire.
            ['emailAddress' => ['address' => 'bob@ex.fr', 'name' => 'Bob'], 'type' => 'required'],
            ['emailAddress' => ['address' => 'carla@ex.fr'], 'type' => 'required'],
        ], $plan['payload']['attendees']);
        $this->assertSame(['carla@ex.fr'], $plan['ajoutes']);
        $this->assertTrue($plan['previent']);

        $retrait = UserOutlookCalendarService::buildUpdatePayload(['retirer_invites' => 'ALICE@ex.fr'], self::evenement());
        $this->assertCount(1, $retrait['payload']['attendees']);
        $this->assertSame(['Alice@ex.fr'], $retrait['retires']);
        $this->assertSame(['bob@ex.fr'], $retrait['invites']);

        // Déjà invité au même titre : rien à envoyer.
        try {
            UserOutlookCalendarService::buildUpdatePayload(['ajouter_invites' => 'alice@ex.fr'], self::evenement());
            $this->fail('Une modification sans effet a été acceptée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Aucune modification', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        UserOutlookCalendarService::buildUpdatePayload(['retirer_invites' => 'zoe@ex.fr'], self::evenement());
    }

    public function testCeQuUneModificationRefuse(): void
    {
        $cas = [
            // Qui n'organise pas ne touche ni au contenu ni aux invités.
            [['titre' => 'Autre'], self::evenement(['isOrganizer' => false, 'organizer' => ['emailAddress' => ['name' => 'Zoé', 'address' => 'zoe@ex.fr']]]), 'organisateur'],
            [['ajouter_invites' => 'c@ex.fr'], self::evenement(['isOrganizer' => false]), 'organisateur'],
            // Piège n° 6 : le corps d'une réunion en ligne porte son lien.
            [['description' => 'Nouvel ordre du jour'], self::evenement(['isOnlineMeeting' => true]), 'lien de connexion'],
            [['titre' => 'X'], self::evenement(['isCancelled' => true]), 'annulé'],
        ];
        foreach ($cas as [$args, $evenement, $mot]) {
            try {
                UserOutlookCalendarService::buildUpdatePayload($args, $evenement);
                $this->fail('Accepté à tort : ' . json_encode($args));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($mot, $e->getMessage());
            }
        }
        // … mais il règle son rappel et sa disponibilité.
        $perso = UserOutlookCalendarService::buildUpdatePayload(['rappel_minutes' => 5], self::evenement(['isOrganizer' => false]));
        $this->assertSame(['isReminderOn' => true, 'reminderMinutesBeforeStart' => 5], $perso['payload']);
        $this->assertFalse($perso['previent']);
    }

    /** Une journée entière se lit par sa DATE : la convertir la ferait reculer d'un jour à l'ouest. */
    public function testUneJourneeEntiereNeReculePasAlOuestDeGreenwich(): void
    {
        $start = ['dateTime' => '2026-08-17T00:00:00.0000000', 'timeZone' => 'UTC'];
        $end   = ['dateTime' => '2026-08-18T00:00:00.0000000', 'timeZone' => 'UTC'];
        $this->assertSame('lundi 17/08/2026 (journée entière)', UserOutlookCalendarService::formatEventBoundary($start, true, 'America/New_York'));
        $this->assertSame('lundi 17/08/2026 (journée entière)', UserOutlookCalendarService::formatEventEnd($end, true, 'America/New_York'));
    }

    /** Tâche sans validation : inviter, c'est écrire à quelqu'un. */
    public function testUneActionQuiPrevientDesInvitesEstReconnue(): void
    {
        $creer    = UserOutlookCalendarService::TOOL_CREATE;
        $modifier = UserOutlookCalendarService::TOOL_UPDATE;
        $this->assertTrue(UserOutlookCalendarService::notifiesAttendees($creer, ['invites' => 'a@ex.fr']));
        $this->assertTrue(UserOutlookCalendarService::notifiesAttendees($creer, ['invites_optionnels' => 'a@ex.fr']));
        $this->assertFalse(UserOutlookCalendarService::notifiesAttendees($creer, ['titre' => 'Seul']));
        $this->assertTrue(UserOutlookCalendarService::notifiesAttendees($modifier, ['previent_invites' => true]));
        $this->assertFalse(UserOutlookCalendarService::notifiesAttendees($modifier, ['titre' => 'X']));
        $this->assertFalse(UserOutlookCalendarService::notifiesAttendees('creer_tache', ['invites' => 'a@ex.fr']));

        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertMatchesRegularExpression(
            '/!\$autoSendMail && \(UserOutlookCalendarService::notifiesAttendees\(\$tool, \$args\)\s*\|\| UserCalendarService::notifiesAttendees\(\$tool, \$args\)\)/',
            $chat,
            'Une tâche sans droit d\'envoi inviterait des gens sans validation (Outlook comme Google).'
        );
        // Posé par le serveur après lecture de l'évènement, jamais lu du modèle.
        $this->assertStringContainsString(
            "\$args['previent_invites'] = \$ctx['previent'];",
            self::lire('src/Agent/Tools/OutlookCalendarTools.php')
        );
    }

    // ===================== Carte d'approbation =====================

    public function testApercuDApprobationAnnonceQuAucuneInvitationNEstEnvoyee(): void
    {
        $apercu = UserOutlookCalendarService::actionPreview('creer_evenement_agenda_outlook', [
            'titre'       => 'Comité budget',
            'debut'       => '2026-08-17 09:00',
            'fin'         => '2026-08-17 10:00',
            'lieu'        => 'Salle 3',
            'description' => str_repeat('a', 400),
        ]);

        $this->assertSame('Créer un évènement dans le calendrier Outlook', $apercu['action']);
        $this->assertStringContainsString('Comité budget', $apercu['fichier']);
        $this->assertStringContainsString('Salle 3', $apercu['fichier']);
        $this->assertSame('/assets/img/outlook-calendar.svg', $apercu['icone']);
        $this->assertStringContainsString('AUCUNE invitation', implode(' ', $apercu['impacts']));
        // Extrait borné : la carte ne déroule pas une description entière.
        $this->assertSame(281, mb_strlen((string)$apercu['apercu']));
    }

    public function testLaCarteNommeLesInvitesAQuiOutlookEcrira(): void
    {
        $apercu = UserOutlookCalendarService::actionPreview(UserOutlookCalendarService::TOOL_CREATE, [
            'titre' => 'Comité', 'debut' => '2026-08-17 09:00', 'visio' => true,
            'invites' => 'alice@ex.fr', 'invites_optionnels' => 'bob@ex.fr',
            'recurrence' => 'hebdomadaire', 'recurrence_fin' => '2026-12-31',
        ]);
        $impacts = implode(' ', $apercu['impacts']);
        $this->assertStringContainsString('alice@ex.fr, bob@ex.fr', $impacts);
        $this->assertStringContainsString('invitation', $impacts);
        $this->assertStringNotContainsString('AUCUNE invitation', $impacts);
        $this->assertStringContainsString('Teams', $impacts);
        $this->assertStringContainsString('chaque semaine', $impacts);

        $modif = UserOutlookCalendarService::actionPreview(
            UserOutlookCalendarService::TOOL_UPDATE,
            ['evenement' => 'AAMk', 'debut' => '2026-08-18 14:00', 'fuseau' => 'Europe/Paris'],
            ['titre' => 'Comité budget', 'quand' => 'lundi 17/08/2026 09:00', 'lien' => 'https://outlook.office365.com/owa/?itemid=x',
             'serie' => 'occurrence', 'previent' => true, 'invites' => ['alice@ex.fr'], 'ajoutes' => ['carla@ex.fr'], 'retires' => []]
        );
        $this->assertSame('Modifier un évènement du calendrier Outlook', $modif['action']);
        // La carte NOMME la réunion : jamais un identifiant.
        $this->assertStringContainsString('Comité budget', $modif['fichier']);
        $this->assertStringNotContainsString('AAMk', $modif['fichier']);
        $this->assertSame('https://outlook.office365.com/owa/?itemid=x', $modif['url']);
        $lignes = implode(' ', $modif['impacts']);
        $this->assertStringContainsString('2026-08-18 14:00', $lignes);
        $this->assertStringContainsString('carla@ex.fr', $lignes);
        $this->assertStringContainsString('occurrence', $lignes);
        $this->assertStringContainsString('par email', $lignes);

        $perso = UserOutlookCalendarService::actionPreview(
            UserOutlookCalendarService::TOOL_UPDATE,
            ['rappel_minutes' => 10],
            ['titre' => 'Comité', 'previent' => false, 'invites' => ['alice@ex.fr']]
        );
        $this->assertStringContainsString('ne seront pas prévenus', implode(' ', $perso['impacts']));
    }

    // ===================== Invariant : aucune écriture destructrice =====================

    /**
     * `Calendars.ReadWrite` ouvre côté API la suppression et l'annulation
     * d'évènements. La promesse « MIA crée et modifie, sous approbation, et ne
     * supprime rien » ne tient que si AUCUN code ne les appelle : c'est ce que
     * garde ce test, pas la consigne du system prompt.
     */
    public function testAucuneSuppressionNiAnnulationDansLeConnecteur(): void
    {
        // Le CODE seul : les commentaires expliquent justement pourquoi ces
        // verbes sont absents.
        $service = self::codeSansCommentaires('src/Outlook/UserOutlookCalendarService.php');

        $this->assertStringNotContainsString("'DELETE'", $service);
        $this->assertStringNotContainsString("'PUT'", $service);
        $this->assertStringNotContainsString('sendMail', $service);
        foreach (['/cancel', '/decline', '/accept', '/forward', 'permanentDelete'] as $action) {
            $this->assertStringNotContainsString($action, $service);
        }

        // UN seul PATCH, sur l'évènement que `fetchForUpdate` vient de relire
        // (identifiant validé, liste des invités recomposée sur son état réel).
        preg_match_all("/apiJson\(\s*'PATCH'\s*,\s*([^,]+),/", $service, $patchs);
        $this->assertCount(1, $patchs[1]);
        $this->assertStringContainsString("'/events/' . rawurlencode(\$id)", $patchs[1][0]);
        $this->assertStringContainsString('$current = $this->fetchForUpdate(', $service);

        // Sans invité demandé, la charge utile n'en porte aucun — c'est leur
        // présence, et non un `sendUpdates`, qui déclenche les invitations.
        $payload = UserOutlookCalendarService::buildEventPayload([
            'titre' => 'Réunion',
            'debut' => '2026-08-17 09:00',
        ]);
        $this->assertArrayNotHasKey('attendees', $payload);
        $this->assertArrayNotHasKey('isOnlineMeeting', $payload);
        $this->assertArrayNotHasKey('recurrence', $payload);

        // Les deux écritures passent par la file d'approbation, et l'exécution
        // re-vérifie les gardes.
        $outils = self::lire('src/Agent/Tools/OutlookCalendarTools.php');
        $this->assertStringContainsString('$queueConnectorWrite(UserOutlookCalendarService::TOOL_CREATE', $outils);
        $this->assertStringContainsString('$queueConnectorWrite(UserOutlookCalendarService::TOOL_UPDATE', $outils);
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertStringContainsString('$msCalendar->updateEvent($action[\'args\'])', $chat);
        $this->assertStringContainsString("'modifier_evenement_agenda_outlook' =>", $chat);

        // Scopes : le minimum pour créer, jamais Calendars.ReadBasic (agenda
        // illisible) ni MailboxSettings (élargissement sans usage).
        $this->assertContains('offline_access', MicrosoftCalendarOAuth::SCOPES);
        $this->assertContains('Calendars.Read', MicrosoftCalendarOAuth::SCOPES);
        $this->assertContains('Calendars.ReadWrite', MicrosoftCalendarOAuth::SCOPES);
        $this->assertNotContains('Calendars.ReadBasic', MicrosoftCalendarOAuth::SCOPES);
        $this->assertNotContains('MailboxSettings.Read', MicrosoftCalendarOAuth::SCOPES);
        $this->assertNotContains('Mail.Read', MicrosoftCalendarOAuth::SCOPES);
    }

    /** Source d'un fichier PHP, commentaires retirés (garde textuelle honnête). */
    private static function codeSansCommentaires(string $chemin): string
    {
        $out = '';
        foreach (token_get_all(self::lire($chemin)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * Câblage complet : un connecteur à moitié branché n'échoue pas, il
     * n'apparaît nulle part — la panne la plus difficile à voir.
     */
    public function testLeConnecteurEstBrancheDeBoutEnBout(): void
    {
        $ligne = ConnectorRegistry::row(ConnectorRegistry::MSCALENDAR);
        $this->assertIsArray($ligne);
        $this->assertSame('microsoft', $ligne['family']);
        $this->assertSame(ConnectorRegistry::SURFACE_CHAT, $ligne['surface']);
        $this->assertSame(UserDriveToken::PROVIDER_MSCALENDAR, $ligne['token']);
        $this->assertSame(UserOutlookCalendarService::SETTING_ENABLED, $ligne['setting']);
        $this->assertContains(ConnectorRegistry::MSCALENDAR, ConnectorRegistry::keys());

        // Jeton DISTINCT de celui d'Outlook : les deux consentements sont
        // séparés, les fusionner ferait demander l'accès aux mails à qui ne veut
        // que son agenda.
        $this->assertNotSame(UserDriveToken::PROVIDER_OUTLOOK, UserDriveToken::PROVIDER_MSCALENDAR);
        $this->assertNotSame(
            UserOutlookService::SETTING_ENABLED,
            UserOutlookCalendarService::SETTING_ENABLED
        );
        $this->assertNotSame(MicrosoftOutlookOAuth::STATE_PREFIX, MicrosoftCalendarOAuth::STATE_PREFIX);

        // Outils exposés au modèle + dispatch de la file d'approbation.
        $chat = self::lire('src/Controllers/ChatController.php');
        foreach (['lister_agendas_outlook', 'rechercher_agenda_outlook',
                  'lire_evenement_agenda_outlook', 'creer_evenement_agenda_outlook'] as $outil) {
            $this->assertStringContainsString("'" . $outil . "'", $chat, "Outil $outil non branché.");
        }
        $this->assertStringContainsString(
            'UserOutlookCalendarService::actionPreview(',
            $chat,
            'La carte d\'approbation de l\'évènement Outlook n\'est pas branchée.'
        );
        $this->assertStringContainsString(
            'UserOutlookCalendarService::forUser((int)$user[\'id\'])',
            $chat,
            'Les gardes ne sont pas re-vérifiées au clic « Approuver ».'
        );

        // Menu « + », interrupteur, phases SSE, suggestion d'intention, pont natif.
        $this->assertStringContainsString('plusMenuMsCalendarRow', self::lire('templates/chat/index.php'));
        $chatJs = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('mscalendarAccessToggle', $chatJs);
        $this->assertStringContainsString('mscalendar_search', $chatJs);
        $this->assertStringContainsString('mscalendar_read', $chatJs);
        $this->assertStringContainsString("connectorRule('mscalendar'", self::lire('public/assets/js/tool-suggest.js'));
        $this->assertStringContainsString('/settings/mscalendar/connect', self::lire('public/assets/js/native-bridge.js'));

        // Page Connexions + icône réellement présente (une carte sans image
        // afficherait un cadre vide).
        $this->assertStringContainsString('/settings/mscalendar/disconnect', self::lire('templates/settings/connections.php'));
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/img/outlook-calendar.svg');

        // Expressions déclencheuses : jamais un mot générique seul.
        $triggers = ConnectorRegistry::defaultTriggers(ConnectorRegistry::MSCALENDAR);
        $this->assertNotEmpty($triggers);
        foreach (['agenda', 'calendrier', 'réunion', 'rendez-vous', 'planning'] as $generique) {
            $this->assertNotContains($generique, $triggers, "« $generique » seul sur-déclencherait.");
        }
        $this->assertContains('agenda outlook', $triggers);
    }
}
