<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Reconnaissance des saisies d'agenda Google (adresse brute, URL publique
 * embed, URL iCal, lien court cid=) — logique pure, sans appel réseau.
 */
final class GoogleCalendarProviderTest extends TestCase
{
    public function testAdresseBrute(): void
    {
        $this->assertSame(
            'alex@gmail.com',
            GoogleCalendarProvider::parseFolderInput('  alex@gmail.com  ')
        );
        $this->assertSame(
            'abc123def@group.calendar.google.com',
            GoogleCalendarProvider::parseFolderInput('abc123def@group.calendar.google.com')
        );
        // Les agendas de jours fériés contiennent un dièse.
        $this->assertSame(
            'fr.french#holiday@group.v.calendar.google.com',
            GoogleCalendarProvider::parseFolderInput('fr.french#holiday@group.v.calendar.google.com')
        );
    }

    public function testUrlEmbedAvecSrcEncode(): void
    {
        $this->assertSame(
            'abc123def@group.calendar.google.com',
            GoogleCalendarProvider::parseFolderInput(
                'https://calendar.google.com/calendar/embed?src=abc123def%40group.calendar.google.com&ctz=Europe%2FParis'
            )
        );
    }

    public function testUrlIcal(): void
    {
        $this->assertSame(
            'abc123def@group.calendar.google.com',
            GoogleCalendarProvider::parseFolderInput(
                'https://calendar.google.com/calendar/ical/abc123def%40group.calendar.google.com/public/basic.ics'
            )
        );
    }

    public function testLienCourtCid(): void
    {
        $id  = 'abc123def@group.calendar.google.com';
        $cid = rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
        $this->assertSame(
            $id,
            GoogleCalendarProvider::parseFolderInput('https://calendar.google.com/calendar/u/0?cid=' . $cid)
        );
    }

    public function testEntreesInvalides(): void
    {
        $this->assertNull(GoogleCalendarProvider::parseFolderInput(''));
        $this->assertNull(GoogleCalendarProvider::parseFolderInput('pas-une-adresse'));
        $this->assertNull(GoogleCalendarProvider::parseFolderInput('adresse avec espaces@gmail.com'));
        $this->assertNull(GoogleCalendarProvider::parseFolderInput('https://example.com/pas-un-agenda'));
        // Un lien Drive n'est pas un agenda.
        $this->assertNull(GoogleCalendarProvider::parseFolderInput('https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOp'));
    }
}
