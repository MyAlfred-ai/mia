<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Récurrence des tâches programmées : forme, calcul des prochaines
 * exécutions (changements d'heure compris), phrase affichée. Entièrement pur.
 *
 * Les dates du calendrier 2026 utilisées ici : jeudi 1er octobre ; passage à
 * l'heure d'été le dimanche 29 mars ; retour à l'heure d'hiver le dimanche
 * 25 octobre (Europe/Paris).
 */
final class RecurrenceTest extends TestCase
{
    private const PARIS = 'Europe/Paris';

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function utc(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    /** Heure française telle que l'affiche Format::time : espaces insécables (U+00A0) autour du « h ». */
    private static function h(string $texte): string
    {
        return (string)preg_replace('/(\d) h (\d)/u', "$1\u{00A0}h\u{00A0}$2", $texte);
    }

    private static function iso(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i');
    }

    // ------------------------------------------------------------------
    // Forme
    // ------------------------------------------------------------------

    public function testLesQuatreFrequencesSeNormalisent(): void
    {
        $this->assertSame(['freq' => 'daily', 'time' => '08:00'], Recurrence::normalize(['freq' => 'daily', 'time' => '8:00']));
        $this->assertSame(['freq' => 'weekdays', 'time' => '07:30'], Recurrence::normalize(['freq' => 'weekdays', 'time' => '07:30']));
        $this->assertSame(
            ['freq' => 'weekly', 'days' => [1, 4], 'time' => '09:05'],
            Recurrence::normalize(['freq' => 'weekly', 'days' => ['4', '1', '4', '9'], 'time' => '9:5'])
        );
        $this->assertSame(['freq' => 'monthly', 'day' => 15, 'time' => '18:00'], Recurrence::normalize(['freq' => 'monthly', 'day' => '15', 'time' => '18:00']));
        $this->assertSame(['freq' => 'monthly', 'day' => 'last', 'time' => '18:00'], Recurrence::normalize(['freq' => 'monthly', 'day' => 'last', 'time' => '18:00']));
    }

    public function testLesChampsSansObjetSontIgnores(): void
    {
        // L'écran poste tout le formulaire : les jours d'un « chaque jour » ne comptent pas.
        $this->assertSame(
            ['freq' => 'daily', 'time' => '08:00'],
            Recurrence::normalize(['freq' => 'daily', 'days' => [2], 'day' => 12, 'time' => '08:00'])
        );
        // Jours en chaîne (« 1,4 ») : acceptés.
        $this->assertSame([1, 4], Recurrence::normalize(['freq' => 'weekly', 'days' => '1,4', 'time' => '08:00'])['days']);
    }

    /** @return iterable<string, array{0: array}> */
    public static function reglesInvalides(): iterable
    {
        yield 'fréquence inconnue' => [['freq' => 'hourly', 'time' => '08:00']];
        yield 'heure absente'      => [['freq' => 'daily', 'time' => '']];
        yield 'heure impossible'   => [['freq' => 'daily', 'time' => '24:00']];
        yield 'minutes impossibles' => [['freq' => 'daily', 'time' => '08:60']];
        yield 'semaine sans jour'  => [['freq' => 'weekly', 'days' => [], 'time' => '08:00']];
        yield 'jour hors semaine'  => [['freq' => 'weekly', 'days' => [0, 8], 'time' => '08:00']];
        yield 'le 29'              => [['freq' => 'monthly', 'day' => 29, 'time' => '08:00']];
        yield 'le 0'               => [['freq' => 'monthly', 'day' => 0, 'time' => '08:00']];
    }

    #[DataProvider('reglesInvalides')]
    public function testUneRegleInvalideEstRefuseeAvecUnMessage(array $input): void
    {
        try {
            Recurrence::normalize($input);
            $this->fail('Règle acceptée à tort : ' . json_encode($input));
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function testLeJsonStockeEstCanoniqueEtRelu(): void
    {
        $json = Recurrence::toJson(['freq' => 'weekly', 'days' => [5, 1], 'time' => '8:00']);
        $this->assertSame('{"freq":"weekly","days":[1,5],"time":"08:00"}', $json);
        $this->assertSame(['freq' => 'weekly', 'days' => [1, 5], 'time' => '08:00'], Recurrence::fromJson($json));
        $this->assertNull(Recurrence::fromJson('{"freq":"weekly","days":[],"time":"08:00"}'));
        $this->assertNull(Recurrence::fromJson('pas du json'));
    }

    // ------------------------------------------------------------------
    // Calcul
    // ------------------------------------------------------------------

    public function testChaqueLundiAHuitHeuresAParis(): void
    {
        $rule = Recurrence::normalize(['freq' => 'weekly', 'days' => [1], 'time' => '08:00']);
        // Jeudi 1er octobre, 10 h UTC → lundi 5 octobre 8 h à Paris (heure d'été : 6 h UTC).
        $next = Recurrence::next($rule, self::PARIS, self::utc('2026-10-01 10:00'));
        $this->assertSame('2026-10-05 06:00', self::iso($next));
        $this->assertSame('UTC', $next->getTimezone()->getName());
    }

    public function testLaProchaineEstStrictementPosterieure(): void
    {
        $rule = Recurrence::normalize(['freq' => 'weekly', 'days' => [1], 'time' => '08:00']);
        // Pile au créneau : c'est la semaine suivante — un créneau traité ne revient pas.
        $this->assertSame('2026-10-12 06:00', self::iso(Recurrence::next($rule, self::PARIS, self::utc('2026-10-05 06:00'))));
    }

    public function testHuitHeuresResteHuitHeuresAuChangementDHeure(): void
    {
        $rule = Recurrence::normalize(['freq' => 'weekly', 'days' => [1], 'time' => '08:00']);
        $avant = Recurrence::next($rule, self::PARIS, self::utc('2026-10-15 00:00'));
        $apres = Recurrence::next($rule, self::PARIS, $avant);
        $this->assertSame('2026-10-19 06:00', self::iso($avant)); // heure d'été (UTC+2)
        $this->assertSame('2026-10-26 07:00', self::iso($apres)); // heure d'hiver (UTC+1)
        $this->assertSame('08:00', $apres->setTimezone(new \DateTimeZone(self::PARIS))->format('H:i'));
    }

    public function testUneHeureInexistanteEstDecaleeDUneHeure(): void
    {
        // 29 mars 2026 : à 2 h, il est 3 h. Une tâche de 2 h 30 part à 3 h 30.
        $rule = Recurrence::normalize(['freq' => 'daily', 'time' => '02:30']);
        $jour = Recurrence::next($rule, self::PARIS, self::utc('2026-03-28 12:00'));
        $this->assertSame('2026-03-29 01:30', self::iso($jour));
        $this->assertSame('03:30', $jour->setTimezone(new \DateTimeZone(self::PARIS))->format('H:i'));
        // Et le lendemain, retour à 2 h 30.
        $this->assertSame('2026-03-30 00:30', self::iso(Recurrence::next($rule, self::PARIS, $jour)));
    }

    public function testUneHeureAmbigueNeDonneQuUneExecution(): void
    {
        // 25 octobre 2026 : 2 h 30 existe deux fois. Une seule exécution ce jour-là.
        $rule = Recurrence::normalize(['freq' => 'daily', 'time' => '02:30']);
        $jour = Recurrence::next($rule, self::PARIS, self::utc('2026-10-24 12:00'));
        $lendemain = Recurrence::next($rule, self::PARIS, $jour);
        $this->assertSame('2026-10-25', $jour->setTimezone(new \DateTimeZone(self::PARIS))->format('Y-m-d'));
        $this->assertSame('2026-10-26', $lendemain->setTimezone(new \DateTimeZone(self::PARIS))->format('Y-m-d'));
    }

    public function testLesJoursOuvresSautentLeWeekEnd(): void
    {
        $rule = Recurrence::normalize(['freq' => 'weekdays', 'time' => '08:00']);
        // Vendredi 2 octobre, 9 h à Paris : la prochaine est lundi.
        $next = Recurrence::next($rule, self::PARIS, self::utc('2026-10-02 07:00'));
        $this->assertSame('2026-10-05 06:00', self::iso($next));
    }

    public function testLeDernierJourDuMoisSuitLaLongueurDuMois(): void
    {
        $rule = Recurrence::normalize(['freq' => 'monthly', 'day' => 'last', 'time' => '09:00']);
        $this->assertSame('2026-02-28', Recurrence::next($rule, 'UTC', self::utc('2026-02-10 00:00'))->format('Y-m-d'));
        $this->assertSame('2028-02-29', Recurrence::next($rule, 'UTC', self::utc('2028-02-10 00:00'))->format('Y-m-d'));
        $this->assertSame('2026-04-30', Recurrence::next($rule, 'UTC', self::utc('2026-03-31 10:00'))->format('Y-m-d'));
    }

    public function testUnJourDuMoisPasseRenvoieAuMoisSuivant(): void
    {
        $rule = Recurrence::normalize(['freq' => 'monthly', 'day' => 1, 'time' => '09:00']);
        $this->assertSame('2026-11-01 09:00', self::iso(Recurrence::next($rule, 'UTC', self::utc('2026-10-01 10:00'))));
    }

    public function testUnFuseauInconnuRetombeSurUtc(): void
    {
        $rule = Recurrence::normalize(['freq' => 'daily', 'time' => '08:00']);
        $this->assertSame('2026-10-02 08:00', self::iso(Recurrence::next($rule, 'Mars/Olympus', self::utc('2026-10-01 10:00'))));
        $this->assertSame('UTC', Recurrence::zone('')->getName());
    }

    public function testLesTroisProchainesSontDansLOrdre(): void
    {
        $rule = Recurrence::normalize(['freq' => 'weekly', 'days' => [1, 4], 'time' => '08:00']);
        $dates = array_map([self::class, 'iso'], Recurrence::upcoming($rule, self::PARIS, self::utc('2026-10-01 10:00')));
        $this->assertSame(['2026-10-05 06:00', '2026-10-08 06:00', '2026-10-12 06:00'], $dates);
    }

    public function testLeVolumeMensuelEstUnOrdreDeGrandeur(): void
    {
        $this->assertSame(30, Recurrence::perMonth(['freq' => 'daily', 'time' => '08:00']));
        $this->assertSame(22, Recurrence::perMonth(['freq' => 'weekdays', 'time' => '08:00']));
        $this->assertSame(4, Recurrence::perMonth(['freq' => 'weekly', 'days' => [1], 'time' => '08:00']));
        $this->assertSame(9, Recurrence::perMonth(['freq' => 'weekly', 'days' => [1, 4], 'time' => '08:00']));
        $this->assertSame(1, Recurrence::perMonth(['freq' => 'monthly', 'day' => 1, 'time' => '08:00']));
    }

    // ------------------------------------------------------------------
    // Phrase
    // ------------------------------------------------------------------

    public function testLaPhraseEnFrancais(): void
    {
        $this->assertSame(self::h('Tous les jours à 8 h 00'), Recurrence::describe(['freq' => 'daily', 'time' => '08:00']));
        $this->assertSame(self::h('Du lundi au vendredi à 7 h 30'), Recurrence::describe(['freq' => 'weekdays', 'time' => '07:30']));
        $this->assertSame(self::h('Chaque lundi à 8 h 00'), Recurrence::describe(['freq' => 'weekly', 'days' => [1], 'time' => '08:00']));
        $this->assertSame(self::h('Chaque lundi, mercredi et vendredi à 8 h 00'), Recurrence::describe(['freq' => 'weekly', 'days' => [1, 3, 5], 'time' => '08:00']));
        // Les sept jours, ou les cinq ouvrés, cochés à la main : la même phrase qu'avec le préréglage.
        $this->assertSame(self::h('Tous les jours à 8 h 00'), Recurrence::describe(['freq' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7], 'time' => '08:00']));
        $this->assertSame(self::h('Du lundi au vendredi à 8 h 00'), Recurrence::describe(['freq' => 'weekly', 'days' => [1, 2, 3, 4, 5], 'time' => '08:00']));
        $this->assertSame(self::h('Le 1er de chaque mois à 9 h 00'), Recurrence::describe(['freq' => 'monthly', 'day' => 1, 'time' => '09:00']));
        $this->assertSame(self::h('Le 15 de chaque mois à 9 h 00'), Recurrence::describe(['freq' => 'monthly', 'day' => 15, 'time' => '09:00']));
        $this->assertSame(self::h('Le dernier jour de chaque mois à 18 h 00'), Recurrence::describe(['freq' => 'monthly', 'day' => 'last', 'time' => '18:00']));
    }

    public function testLaPhraseEnAnglais(): void
    {
        I18n::use('en');
        $this->assertSame('Every Monday, Wednesday and Friday at 08:00', Recurrence::describe(['freq' => 'weekly', 'days' => [1, 3, 5], 'time' => '08:00']));
        $this->assertSame('Every Saturday and Sunday at 10:15', Recurrence::describe(['freq' => 'weekly', 'days' => [6, 7], 'time' => '10:15']));
        $this->assertSame('Monday to Friday at 07:30', Recurrence::describe(['freq' => 'weekdays', 'time' => '07:30']));
        $this->assertSame('On day 15 of every month at 09:00', Recurrence::describe(['freq' => 'monthly', 'day' => 15, 'time' => '09:00']));
    }

    /** Les noms des jours sont des clés CALCULÉES : le test de couverture du dictionnaire ne les voit pas. */
    public function testLesSeptJoursSontTraduits(): void
    {
        $dict = (array)(require ROOT . '/lang/en.php');
        foreach (['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'] as $jour) {
            $this->assertArrayHasKey($jour, $dict, "Jour non traduit : $jour");
        }
        I18n::use('en');
        $this->assertSame('Monday', Recurrence::dayName(1));
        $this->assertSame('Sunday', Recurrence::dayName(7));
        $this->assertSame('', Recurrence::dayName(8));
    }

    public function testLesHeuresEtDatesCourtesSuiventLaLocale(): void
    {
        $date = new \DateTimeImmutable('2026-10-05 08:05', new \DateTimeZone(self::PARIS));
        $this->assertSame(self::h('8 h 05'), Format::time('08:05'));
        $this->assertSame(self::h('lun. 5 oct. à 8 h 05'), Format::shortDateTime($date));
        $this->assertSame('lun.', Format::dayShort(1));
        $this->assertSame('', Format::time('demain'));

        I18n::use('en');
        $this->assertSame('08:05', Format::time('8:05'));
        $this->assertSame('Mon, Oct 5 at 08:05', Format::shortDateTime($date));
        $this->assertSame('Sun', Format::dayShort(7));
    }
}
