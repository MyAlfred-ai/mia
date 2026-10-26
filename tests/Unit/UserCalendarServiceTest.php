<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Logique pure du connecteur Google Agenda utilisateur : validation des
 * saisies de dates, construction du corps events.insert (journée entière vs
 * horodaté, participants), formatage FR des bornes, aperçu d'approbation —
 * sans aucun appel réseau.
 */
final class UserCalendarServiceTest extends TestCase
{
    public function testNormalizeCalendarId(): void
    {
        $this->assertSame('primary', UserCalendarService::normalizeCalendarId(''));
        $this->assertSame('primary', UserCalendarService::normalizeCalendarId('  Primary '));
        $this->assertSame('primary', UserCalendarService::normalizeCalendarId('principal'));
        $this->assertSame('equipe@group.calendar.google.com', UserCalendarService::normalizeCalendarId('equipe@group.calendar.google.com'));

        $this->expectException(RuntimeException::class);
        UserCalendarService::normalizeCalendarId('id avec espaces');
    }

    public function testParseDateInputJourEtHeure(): void
    {
        $d = UserCalendarService::parseDateInput('2026-08-07', false);
        $this->assertSame('2026-08-07 00:00', $d->format('Y-m-d H:i'));

        // Fin de fenêtre : borne exclusive au lendemain (jour inclus).
        $fin = UserCalendarService::parseDateInput('2026-08-07', true);
        $this->assertSame('2026-08-08 00:00', $fin->format('Y-m-d H:i'));

        $dt = UserCalendarService::parseDateInput('2026-08-07 14:30', false);
        $this->assertSame('2026-08-07 14:30', $dt->format('Y-m-d H:i'));

        $this->assertNull(UserCalendarService::parseDateInput('', false));
    }

    public function testParseDateInputInvalide(): void
    {
        $this->expectException(RuntimeException::class);
        UserCalendarService::parseDateInput('07/08/2026', false);
    }

    public function testBuildEventPayloadHorodate(): void
    {
        $p = UserCalendarService::buildEventPayload([
            'titre' => 'Réunion budget',
            'debut' => '2026-08-07 09:00',
            'fin'   => '2026-08-07 10:30',
            'lieu'  => 'Salle A',
        ]);
        $this->assertSame('Réunion budget', $p['summary']);
        $this->assertSame('Salle A', $p['location']);
        $this->assertStringStartsWith('2026-08-07T09:00', $p['start']['dateTime']);
        $this->assertStringStartsWith('2026-08-07T10:30', $p['end']['dateTime']);
        $this->assertArrayNotHasKey('attendees', $p);

        // Fin absente → début + 1 h.
        $p = UserCalendarService::buildEventPayload(['titre' => 'Point', 'debut' => '2026-08-07 09:00']);
        $this->assertStringStartsWith('2026-08-07T10:00', $p['end']['dateTime']);
    }

    public function testBuildEventPayloadJourneeEntiere(): void
    {
        // Dates sans heure → journée entière implicite, fin Google EXCLUSIVE (+1 jour).
        $p = UserCalendarService::buildEventPayload([
            'titre' => 'Salon',
            'debut' => '2026-08-07',
            'fin'   => '2026-08-08',
        ]);
        $this->assertSame('2026-08-07', $p['start']['date']);
        $this->assertSame('2026-08-09', $p['end']['date']);

        // Un seul jour.
        $p = UserCalendarService::buildEventPayload(['titre' => 'Férié', 'debut' => '2026-08-15', 'journee_entiere' => true]);
        $this->assertSame('2026-08-15', $p['start']['date']);
        $this->assertSame('2026-08-16', $p['end']['date']);
    }

    public function testBuildEventPayloadParticipantsValides(): void
    {
        $p = UserCalendarService::buildEventPayload([
            'titre'   => 'Kickoff',
            'debut'   => '2026-08-07 09:00',
            'invites' => 'alice@example.com, bob@example.com',
        ]);
        $this->assertSame(
            [['email' => 'alice@example.com'], ['email' => 'bob@example.com']],
            $p['attendees']
        );

        $this->expectException(RuntimeException::class);
        UserCalendarService::buildEventPayload([
            'titre' => 'X', 'debut' => '2026-08-07 09:00', 'invites' => 'pas-une-adresse',
        ]);
    }

    public function testBuildEventPayloadErreurs(): void
    {
        // Titre manquant.
        try {
            UserCalendarService::buildEventPayload(['debut' => '2026-08-07 09:00']);
            $this->fail('Titre manquant non détecté');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Titre', $e->getMessage());
        }
        // Fin avant début.
        try {
            UserCalendarService::buildEventPayload([
                'titre' => 'X', 'debut' => '2026-08-07 10:00', 'fin' => '2026-08-07 09:00',
            ]);
            $this->fail('Fin avant début non détectée');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fin', mb_strtolower($e->getMessage()));
        }
        // Journée entière avec une heure.
        try {
            UserCalendarService::buildEventPayload([
                'titre' => 'X', 'debut' => '2026-08-07 09:00', 'journee_entiere' => true,
            ]);
            $this->fail('Journée entière avec heure non détectée');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('journée entière', mb_strtolower($e->getMessage()));
        }
    }

    public function testFormatEventBoundary(): void
    {
        $this->assertSame(
            'vendredi 07/08/2026 09:00',
            UserCalendarService::formatEventBoundary(['dateTime' => '2026-08-07T09:00:00+02:00'])
        );
        $this->assertSame(
            'samedi 15/08/2026 (journée entière)',
            UserCalendarService::formatEventBoundary(['date' => '2026-08-15'])
        );
        $this->assertSame('', UserCalendarService::formatEventBoundary(null));
    }

    public function testActionPreview(): void
    {
        $p = UserCalendarService::actionPreview('creer_evenement_agenda', [
            'titre'   => 'Réunion budget',
            'debut'   => '2026-08-07 09:00',
            'fin'     => '2026-08-07 10:30',
            'lieu'    => 'Salle A',
            'invites' => 'alice@example.com',
            'description' => 'Ordre du jour : budget T3.',
        ]);
        $this->assertSame('Créer un évènement Google Agenda', $p['action']);
        $this->assertStringContainsString('Réunion budget', $p['fichier']);
        $this->assertStringContainsString('Salle A', $p['fichier']);
        $this->assertStringContainsString('SANS envoi d\'invitation', implode(' ', $p['impacts']));
        $this->assertSame('Ordre du jour : budget T3.', $p['apercu']);
        $this->assertSame('/assets/img/google-calendar.svg', $p['icone']);
    }
}
