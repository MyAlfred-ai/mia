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
 *    donc le connecteur refuse d'en ajouter.
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
            $this->assertArrayNotHasKey('isAllDay', $payload);
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

    /**
     * LE piège n° 4 : Graph n'a AUCUN équivalent de `sendUpdates=none`. Un
     * évènement créé avec des participants les invite. Ignorer la demande en
     * silence laisserait croire des invités conviés : on refuse, en l'expliquant.
     */
    public function testAjouterDesParticipantsEstRefuseAvecExplication(): void
    {
        foreach ([['invites' => 'alice@ex.fr'], ['participants' => ['alice@ex.fr']]] as $args) {
            try {
                UserOutlookCalendarService::buildEventPayload(
                    $args + ['titre' => 'Réunion', 'debut' => '2026-08-17 09:00']
                );
                $this->fail('Participants acceptés à tort.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('invitation', $e->getMessage());
            }
        }
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

    // ===================== Invariant : aucune écriture destructrice =====================

    /**
     * `Calendars.ReadWrite` ouvre côté API la modification et la suppression
     * d'évènements. La promesse « MIA ne fait que consulter et créer » ne tient
     * que si AUCUN code ne les appelle : c'est ce que garde ce test, pas la
     * consigne du system prompt.
     */
    public function testAucuneModificationNiSuppressionDansLeConnecteur(): void
    {
        // Le CODE seul : les commentaires expliquent justement pourquoi ces
        // verbes sont absents.
        $service = self::codeSansCommentaires('src/Outlook/UserOutlookCalendarService.php');

        $this->assertStringNotContainsString("'DELETE'", $service);
        $this->assertStringNotContainsString("'PATCH'", $service);
        $this->assertStringNotContainsString("'PUT'", $service);
        $this->assertStringNotContainsString('sendMail', $service);

        // Et surtout : la charge utile de création ne porte JAMAIS de
        // participants — c'est leur présence, et non un `sendUpdates`, qui
        // déclenche l'envoi des invitations chez Graph. (Les lire reste
        // légitime : `readEvent` les affiche.)
        $payload = UserOutlookCalendarService::buildEventPayload([
            'titre' => 'Réunion',
            'debut' => '2026-08-17 09:00',
        ]);
        $this->assertArrayNotHasKey('attendees', $payload);
        $this->assertArrayNotHasKey('isOnlineMeeting', $payload);

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
