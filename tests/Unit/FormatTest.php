<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Formatage d'affichage centralisé (euros, tokens, crédits, dates). */
final class FormatTest extends TestCase
{
    public function testEurZeroEtNegatif(): void
    {
        $this->assertSame('0,00 €', Format::eur(0.0));
        $this->assertSame('0,00 €', Format::eur(-1.5));
    }

    public function testEurPrecisionAdaptee(): void
    {
        $this->assertSame('0,0050 €', Format::eur(0.005));
        $this->assertSame('0,500 €', Format::eur(0.5));
        $this->assertSame('12,50 €', Format::eur(12.5));
        $this->assertSame('1 234,56 €', Format::eur(1234.56));
    }

    public function testTokens(): void
    {
        $this->assertSame('842', Format::tokens(842));
        $this->assertSame('9,5 k', Format::tokens(9500));
        $this->assertSame('13 k', Format::tokens(12500));
        $this->assertSame('1,25 M', Format::tokens(1_250_000));
    }

    public function testCredits(): void
    {
        $this->assertSame('12 840', Format::credits(12840));
        $this->assertSame('0', Format::credits(0));
    }

    public function testMonthLabel(): void
    {
        $this->assertSame('juil. 2026', Format::monthLabel('2026-07'));
        $this->assertSame('janv. 2025', Format::monthLabel('2025-01'));
        $this->assertSame('', Format::monthLabel('2026-13'));
        $this->assertSame('', Format::monthLabel('n/a'));
    }

    public function testDateFr(): void
    {
        $this->assertSame('12/07/2026', Format::dateFr('2026-07-12'));
        $this->assertSame('12/07/2026', Format::dateFr('2026-07-12 15:04:05'));
        $this->assertSame('', Format::dateFr(''));
        $this->assertSame('', Format::dateFr('pas-une-date'));
    }
}
