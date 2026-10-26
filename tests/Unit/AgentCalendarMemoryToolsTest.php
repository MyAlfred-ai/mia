<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/AgentSource.php';

/**
 * Créneaux libres, fuseau de l'utilisateur dans les agendas, recherche dans
 * les anciennes conversations et outil `memoriser`.
 *
 * Ce que ces tests protègent :
 *   - le calcul des créneaux (fusion des occupations, heures de travail,
 *     week-ends, passé exclu, quart d'heure, changement d'heure) ;
 *   - le FUSEAU : le serveur tourne en UTC, un évènement « demain 14 h » était
 *     créé à 14 h UTC — l'heure de l'utilisateur voyage désormais avec l'action ;
 *   - que la recherche de conversations ne s'expose que sur une allusion au
 *     passé (ou quand la boucle tourne déjà) et que `memoriser` refuse un secret.
 */
final class AgentCalendarMemoryToolsTest extends TestCase
{
    private const TZ = 'Europe/Paris';

    private function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone(self::TZ));
    }

    /** @return list<array{0:\DateTimeImmutable,1:\DateTimeImmutable}> */
    private function busy(array $pairs): array
    {
        return array_map(fn(array $p): array => [$this->at($p[0]), $this->at($p[1])], $pairs);
    }

    // ===================== FreeSlots : demande =====================

    public function testLaDemandeParDefautCouvreSeptJoursDeNeufHeuresADixHuit(): void
    {
        $now = $this->at('2026-10-05 08:12');
        $req = FreeSlots::request([], self::TZ, $now);
        $this->assertSame(self::TZ, $req['tz']->getName());
        $this->assertSame('2026-10-05 00:00', $req['from']->format('Y-m-d H:i'));
        $this->assertSame('2026-10-12 00:00', $req['to']->format('Y-m-d H:i'));
        $this->assertSame(60, $req['duree']);
        $this->assertSame(9 * 60, $req['jour_debut']);
        $this->assertSame(18 * 60, $req['jour_fin']);
        $this->assertTrue($req['jours_ouvres']);
        $this->assertSame([], $req['participants']);
    }

    public function testLesEntreesInvalidesSontRefuseesAvecUnMessageClair(): void
    {
        $now = $this->at('2026-10-05 08:00');
        $cases = [
            [['debut' => '05/10/2026'], 'Date invalide'],
            [['debut' => '2026-10-09', 'fin' => '2026-10-06'], 'postérieure'],
            [['debut' => '2026-10-05', 'fin' => '2026-12-31'], 'Fenêtre trop large'],
            [['heure_debut' => '25:00'], 'Heure invalide'],
            [['heure_debut' => '18:00', 'heure_fin' => '09:00'], 'heure de fin'],
            [['heure_debut' => '09:00', 'heure_fin' => '10:00', 'duree_minutes' => 120], 'dépasse'],
            [['participants' => 'paul@exemple.fr, pas-une-adresse'], 'participant invalide'],
            [['debut' => '2026-09-01', 'fin' => '2026-09-02'], 'passée'],
        ];
        foreach ($cases as [$args, $attendu]) {
            try {
                FreeSlots::request($args, self::TZ, $now);
                $this->fail('Entrée acceptée à tort : ' . json_encode($args));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($attendu, $e->getMessage());
            }
        }
        $req = FreeSlots::request(['participants' => 'Paul@Exemple.fr; paul@exemple.fr, marie@exemple.fr'], self::TZ, $now);
        $this->assertCount(2, $req['participants'], 'Participants dédoublonnés, casse ignorée.');
        $this->assertSame(15, FreeSlots::request(['duree_minutes' => 5], self::TZ, $now)['duree']);
    }

    // ===================== FreeSlots : calcul =====================

    public function testLesCreneauxEvitentLesOccupationsEtRespectentLaJournee(): void
    {
        $now = $this->at('2026-10-05 07:00'); // lundi
        $req = FreeSlots::request(['fin' => '2026-10-05', 'duree_minutes' => 60], self::TZ, $now);
        $free = FreeSlots::compute($this->busy([
            ['2026-10-05 09:30', '2026-10-05 10:30'],
            ['2026-10-05 10:00', '2026-10-05 11:15'],   // chevauche la précédente : fusion
            ['2026-10-05 12:00', '2026-10-05 13:00'],
            ['2026-10-05 17:30', '2026-10-05 19:00'],   // déborde la journée
        ]), $req, $now);
        $out = FreeSlots::format($free, $req['tz']);
        $this->assertSame(
            [['13:00', '17:30']],
            array_map(static fn(array $c): array => [$c['de'], $c['a']], $out),
            '09:00-09:30 et 11:15-12:00 sont trop courts pour une heure.'
        );
        $this->assertSame('2026-10-05 13:00', $out[0]['debut'], 'Prêt pour la création d\'évènement.');
        $this->assertSame('4 h 30', $out[0]['duree']);
        $this->assertSame('lundi 05/10/2026', $out[0]['jour']);
    }

    public function testLePasseEstExcluEtLesDebutsTombentAuQuartDHeure(): void
    {
        $now = $this->at('2026-10-05 10:07');
        $req = FreeSlots::request(['fin' => '2026-10-05', 'duree_minutes' => 30], self::TZ, $now);
        $free = FreeSlots::format(FreeSlots::compute($this->busy([['2026-10-05 11:05', '2026-10-05 12:00']]), $req, $now), $req['tz']);
        $this->assertSame('10:15', $free[0]['de'], 'Jamais dans le passé, arrondi au quart d\'heure suivant.');
        $this->assertSame('11:05', $free[0]['a']);
        $this->assertSame('12:00', $free[1]['de']);
    }

    public function testLesWeekEndsSontExclusParDefautEtInclusSurDemande(): void
    {
        $now = $this->at('2026-10-09 19:00'); // vendredi soir
        $req = FreeSlots::request(['fin' => '2026-10-12'], self::TZ, $now);
        $jours = array_column(FreeSlots::format(FreeSlots::compute([], $req, $now), $req['tz']), 'jour');
        $this->assertSame(['lundi 12/10/2026'], $jours);

        $req2 = FreeSlots::request(['fin' => '2026-10-12', 'jours_ouvres' => false], self::TZ, $now);
        $jours2 = array_column(FreeSlots::format(FreeSlots::compute([], $req2, $now), $req2['tz']), 'jour');
        $this->assertSame(['samedi 10/10/2026', 'dimanche 11/10/2026', 'lundi 12/10/2026'], $jours2);
    }

    public function testLeChangementDHeureGardeLesHeuresLocales(): void
    {
        // Passage à l'heure d'hiver le dimanche 25/10/2026 : lundi 26, 9 h reste 9 h.
        $now = $this->at('2026-10-23 08:00');
        $req = FreeSlots::request(['debut' => '2026-10-26', 'fin' => '2026-10-26'], self::TZ, $now);
        $free = FreeSlots::compute([], $req, $now);
        $this->assertSame('09:00', $free[0][0]->setTimezone(new \DateTimeZone(self::TZ))->format('H:i'));
        $this->assertSame('+01:00', $free[0][0]->format('P'));
    }

    public function testLesOccupationsSontFusionneesQuelQueSoitLeurFuseau(): void
    {
        $merged = FreeSlots::merge([
            [new \DateTimeImmutable('2026-10-05T08:00:00Z'), new \DateTimeImmutable('2026-10-05T09:00:00Z')],
            [$this->at('2026-10-05 10:30'), $this->at('2026-10-05 12:00')], // 08:30Z → chevauche
            [$this->at('2026-10-05 15:00'), $this->at('2026-10-05 14:00')], // à l'envers : ignorée
        ]);
        $this->assertCount(1, $merged);
        $this->assertSame('2026-10-05T10:00:00Z', $merged[0][1]->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
    }

    public function testLaRechercheCompleteSignaleLesParticipantsNonVisibles(): void
    {
        $now = $this->at('2026-10-05 07:00');
        $fake = new class {
            public array $called = [];
            public function busyIntervals(\DateTimeImmutable $f, \DateTimeImmutable $t, array $p): array
            {
                $this->called = $p;
                return [
                    'busy'         => [[new \DateTimeImmutable('2026-10-05T07:00:00Z'), new \DateTimeImmutable('2026-10-05T14:00:00Z')]],
                    'visibles'     => ['marie@exemple.fr'],
                    'non_visibles' => ['paul@autre.fr' => 'agenda introuvable ou non partagé avec ce compte'],
                ];
            }
        };
        $r = FreeSlots::find($fake, ['fin' => '2026-10-05', 'participants' => 'marie@exemple.fr, paul@autre.fr'], self::TZ, $now);
        $this->assertSame(['marie@exemple.fr', 'paul@autre.fr'], $fake->called);
        $this->assertSame(self::TZ, $r['fuseau']);
        $this->assertSame('16:00', $r['creneaux'][0]['de'], '14:00Z = 16:00 à Paris : le calcul est fait dans le fuseau de l\'utilisateur.');
        $this->assertSame(['marie@exemple.fr'], $r['participants_pris_en_compte']);
        $this->assertStringContainsString('paul@autre.fr', $r['participants_non_visibles'][0]);
        $this->assertStringContainsString('PAS prises en compte', $r['note']);
    }

    // ===================== Fuseau des agendas =====================

    public function testFreeBusyGoogleSeparelAgendaDuCompteDesParticipants(): void
    {
        $r = UserCalendarService::parseFreeBusy([
            'calendars' => [
                'primary'          => ['busy' => [['start' => '2026-10-05T07:00:00Z', 'end' => '2026-10-05T08:00:00Z']]],
                'marie@exemple.fr' => ['busy' => [['start' => '2026-10-05T09:00:00Z', 'end' => '2026-10-05T10:00:00Z']]],
                'paul@autre.fr'    => ['errors' => [['domain' => 'global', 'reason' => 'notFound']], 'busy' => []],
            ],
        ], ['marie@exemple.fr', 'paul@autre.fr', 'luc@exemple.fr']);
        $this->assertCount(2, $r['busy']);
        $this->assertSame(['marie@exemple.fr'], $r['visibles']);
        $this->assertStringContainsString('non partagé', $r['non_visibles']['paul@autre.fr']);
        $this->assertArrayHasKey('luc@exemple.fr', $r['non_visibles'], 'Absent de la réponse = non visible, jamais libre.');
    }

    public function testUnEvenementGoogleEstCreeALHeureDeLUtilisateur(): void
    {
        $p = UserCalendarService::buildEventPayload(['titre' => 'Point', 'debut' => '2026-10-06 14:00', 'fuseau' => self::TZ]);
        $this->assertSame('2026-10-06T14:00:00+02:00', $p['start']['dateTime']);
        $this->assertSame(self::TZ, $p['start']['timeZone']);
        // Fuseau inconnu ou absent : celui du serveur (comportement d'avant).
        $q = UserCalendarService::buildEventPayload(['titre' => 'Point', 'debut' => '2026-10-06 14:00', 'fuseau' => 'Mars/Olympus']);
        $this->assertSame(date_default_timezone_get(), $q['start']['timeZone']);
        $this->assertSame(self::TZ, UserCalendarService::argTimeZone(['fuseau' => self::TZ]));
        $this->assertSame('', UserCalendarService::validTimeZone('Mars/Olympus'));
        $this->assertStringContainsString('(Europe/Paris)',
            UserCalendarService::actionPreview('creer_evenement_agenda', ['titre' => 'Point', 'debut' => '2026-10-06 14:00', 'fuseau' => self::TZ])['fichier'],
            'La carte nomme le fuseau de l\'heure qui sera bloquée.');
    }

    public function testUnEvenementOutlookEstCreeEtAfficheALHeureDeLUtilisateur(): void
    {
        $p = UserOutlookCalendarService::buildEventPayload(['titre' => 'Point', 'debut' => '2026-10-06 14:00', 'fuseau' => self::TZ]);
        $this->assertSame('2026-10-06T14:00:00', $p['start']['dateTime']);
        $this->assertSame(self::TZ, $p['start']['timeZone']);
        // Graph rend l'heure en UTC sans décalage : 07:00 UTC = 09:00 à Paris.
        $this->assertSame('mardi 06/10/2026 09:00',
            UserOutlookCalendarService::formatEventBoundary(['dateTime' => '2026-10-06T07:00:00.0000000', 'timeZone' => 'UTC'], false, self::TZ));
    }

    public function testLesOccupationsOutlookSuiventShowAsEtGetSchedule(): void
    {
        $this->assertNull(UserOutlookCalendarService::busySlot(['showAs' => 'free',
            'start' => ['dateTime' => '2026-10-05T08:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-05T09:00:00', 'timeZone' => 'UTC']]));
        $this->assertNull(UserOutlookCalendarService::busySlot(['showAs' => 'busy', 'isCancelled' => true,
            'start' => ['dateTime' => '2026-10-05T08:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-05T09:00:00', 'timeZone' => 'UTC']]));
        $this->assertNotNull(UserOutlookCalendarService::busySlot(['showAs' => 'tentative',
            'start' => ['dateTime' => '2026-10-05T08:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-05T09:00:00', 'timeZone' => 'UTC']]),
            'Une réunion provisoire bloque : proposer ce créneau créerait le conflit.');

        $r = UserOutlookCalendarService::parseSchedule(['value' => [
            ['scheduleId' => 'Marie@Exemple.fr', 'scheduleItems' => [
                ['status' => 'busy', 'start' => ['dateTime' => '2026-10-05T08:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-05T09:00:00', 'timeZone' => 'UTC']],
                ['status' => 'free', 'start' => ['dateTime' => '2026-10-05T10:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-05T11:00:00', 'timeZone' => 'UTC']],
            ]],
            ['scheduleId' => 'paul@autre.fr', 'error' => ['message' => 'not found', 'responseCode' => 'ErrorMailboxNotFound']],
        ]], ['marie@exemple.fr', 'paul@autre.fr']);
        $this->assertCount(1, $r['busy']);
        $this->assertSame(['marie@exemple.fr'], $r['visibles']);
        $this->assertArrayHasKey('paul@autre.fr', $r['non_visibles']);
    }

    public function testLesOutilsAgendaPortentLeFuseauEtLesCreneaux(): void
    {
        foreach (['GoogleCalendarTools' => ['trouver_creneaux_agenda', 'creer_evenement_agenda'],
                     'OutlookCalendarTools' => ['trouver_creneaux_outlook', 'creer_evenement_agenda_outlook']] as $class => [$slots, $create]) {
            $src = AgentSource::tools($class);
            $this->assertStringContainsString('$calendar->setTimeZone($turn->timeZone);', $src);
            $this->assertStringContainsString("FreeSlots::toolDefinition('" . $slots . "'", $src);
            $this->assertStringContainsString("\$toolHandlers['" . $slots . "']", $src);
            $this->assertStringContainsString('FreeSlots::find(', $src);
            // La définition des créneaux ne dépend pas du droit d'écriture.
            $this->assertLessThan(strpos($src, 'if ($calendar->canWrite()) {' . "\n" . '            $defs[] = ['),
                strpos($src, "FreeSlots::toolDefinition('" . $slots . "'"));
            $this->assertMatchesRegularExpression("/toolHandlers\['" . $create . "'\][^;]*?\{\s*(?:\/\/[^\n]*\n\s*)*if \(\\\$this->turn->timeZone !== ''/s", $src,
                'Le fuseau voyage avec l\'action de création.');
            $this->assertStringContainsString("FreeSlots::zone(\$this->turn->timeZone)", $src, 'Date du jour dans le fuseau de l\'utilisateur.');
        }
        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertMatchesRegularExpression('/\$queueConnectorWrite,\s*(?:\/\/[^\n]*\n\s*)*\$timeZone,\s*\$searchScope\s*\);/', $chat,
            'Le fuseau du navigateur rejoint le contexte du tour.');
    }

    // ===================== Anciennes conversations =====================

    public function testLesMotsSignificatifsDUneRequete(): void
    {
        $this->assertSame(['plan', 'marketing', '2026'], ConversationSearch::tokens('Le plan marketing de 2026 dont on avait parlé'));
        $this->assertSame([], ConversationSearch::tokens('la dernière fois'));
        $this->assertSame(['q3'], ConversationSearch::tokens('Q3'));
        $this->assertLessThanOrEqual(ConversationSearch::MAX_TOKENS, count(ConversationSearch::tokens('un deux trois quatre cinq six sept huit neuf dix onze')));
    }

    public function testUneAllusionAuPasseEstReconnueEtPasUneDemandeDeMemoire(): void
    {
        foreach (['Reprends le plan de la dernière fois', "Tu m'avais conseillé un outil, lequel ?",
                     'On avait parlé de Qdrant', 'Dans une autre conversation tu disais…', 'Tu te souviens du devis ?',
                     'What did we discuss last time?'] as $q) {
            $this->assertTrue(ConversationSearch::looksLikePastReference($q), $q);
        }
        foreach (['Souviens-toi que je préfère le thé', 'Quelle heure est-il ?', 'Fais un plan marketing',
                     'Le dernier rapport trimestriel'] as $q) {
            $this->assertFalse(ConversationSearch::looksLikePastReference($q), $q);
        }
    }

    public function testLesResultatsSontGroupesParConversationEtClasses(): void
    {
        $rows = [
            ['conv_id' => 7, 'title' => 'Budget', 'updated_at' => '2026-09-01 10:00:00', 'msg_id' => 70, 'role' => 'assistant', 'content' => 'Le plan prévoit trois étapes.'],
            ['conv_id' => 9, 'title' => 'Plan marketing 2026', 'updated_at' => '2026-09-20 10:00:00', 'msg_id' => 90, 'role' => 'user', 'content' => 'On fait le plan marketing ?'],
            ['conv_id' => 9, 'title' => 'Plan marketing 2026', 'updated_at' => '2026-09-20 10:00:00', 'msg_id' => 91, 'role' => 'assistant', 'content' => 'Voici le plan marketing détaillé.'],
            ['conv_id' => 9, 'title' => 'Plan marketing 2026', 'updated_at' => '2026-09-20 10:00:00', 'msg_id' => 92, 'role' => 'assistant', 'content' => 'Encore le plan marketing.'],
        ];
        $g = ConversationSearch::group($rows, ['plan', 'marketing'], 5);
        $this->assertSame('/chat/9', $g[0]['lien'], 'La conversation qui contient TOUS les mots passe en tête.');
        $this->assertSame('20/09/2026', $g[0]['date']);
        $this->assertCount(ConversationSearch::MAX_SNIPPETS, $g[0]['extraits']);
        $this->assertSame('MIA', $g[0]['extraits'][0]['auteur']);
        $this->assertSame('/chat/7', $g[1]['lien']);
        $this->assertCount(1, ConversationSearch::group($rows, ['plan', 'marketing'], 1));
    }

    public function testLExtraitEstCentreSurLeMotSansTenirCompteDesAccents(): void
    {
        $texte = str_repeat('Introduction sans intérêt. ', 30) . 'La réunion budgétaire a eu lieu mardi. ' . str_repeat('Suite. ', 40);
        $extrait = ConversationSearch::snippet($texte, ['reunion'], 120);
        $this->assertStringContainsString('réunion budgétaire', $extrait);
        $this->assertStringStartsWith('…', $extrait);
        $this->assertStringNotContainsString('![', ConversationSearch::snippet('![image](/assets/img/x.png) Bonjour', ['bonjour']));
        $this->assertSame('%100\\%\\_ok%', ConversationSearch::likePattern('100%_ok'));
    }

    public function testUneRequeteVideNeTouchePasLaBase(): void
    {
        $this->assertArrayHasKey('error', ConversationSearch::search(1, 2, 'la dernière fois'));
    }

    // ===================== memoriser =====================

    public function testUnSecretNEstJamaisMemorise(): void
    {
        foreach (['Mon mot de passe Gmail est Soleil2026', 'Carte 4970 1012 3456 7890', 'IBAN FR76 3000 6000 0112 3456 7890 189',
                     'Ma clé API est sk-xxx', 'Mon code PIN est 1234'] as $s) {
            $this->assertTrue(UserMemory::looksSensitive($s), $s);
        }
        foreach (['Préfère les réunions du matin', 'Travaille au service marketing', 'Téléphone pro : 06 12 34 56 78'] as $s) {
            $this->assertFalse(UserMemory::looksSensitive($s), $s);
        }

        $tools = new ConversationTools($this->turn(), false, true);
        $this->assertSame(['memoriser'], array_column($tools->definitions(), 'name'));
        $r = $tools->handlers()['memoriser'](['fait' => 'Mon mot de passe est hunter2']);
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsString('sensible', $r['error']);
    }

    public function testLesOutilsDeMemoireDependentDeLeursDrapeaux(): void
    {
        $this->assertSame(['rechercher_conversations'], array_column((new ConversationTools($this->turn(), true, false))->definitions(), 'name'));
        $this->assertSame(['rechercher_conversations', 'memoriser'], array_column((new ConversationTools($this->turn(), true, true))->definitions(), 'name'));
        $this->assertNull((new ConversationTools($this->turn(), true, true))->connector(), 'Ce n\'est pas un connecteur : ni plan ni méthode agent pour lui seul.');

        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('$pastReference    = $supportsTools && ConversationSearch::looksLikePastReference($content);', $chat);
        $this->assertStringContainsString('if ($supportsTools && ($pastReference || $loopRunsAnyway || $fileReread)) {', $chat);
        $this->assertStringContainsString('new ConversationTools($turn, true, $loopRunsAnyway && !$unattended, $fileReread)', $chat,
            '`memoriser` seulement quand la boucle tourne déjà ; relecture des fichiers seulement quand la fenêtre les a retirés.');
    }

    private function turn(): AgentTurn
    {
        return new AgentTurn(1, 2, [], 1000, static function (array $e): void {}, static fn(string $t, array $a, ?array $r): array => []);
    }
}
