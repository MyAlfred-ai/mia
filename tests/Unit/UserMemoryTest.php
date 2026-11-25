<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mémoire (Réglages → Mémoire) — briques pures de UserMemory, sans base ni
 * modèle :
 *   - sanitize / normalizeForCompare / fingerprintOf : normalisation,
 *     déduplication, empreinte d'un souvenir refusé ;
 *   - thèmes, verrou, origine affichée ;
 *   - promptSelection / promptFragmentFromRows : ce que le prompt relit, sous
 *     budget, dans un ordre déterministe ;
 *   - emptyStateNote : « que sais-tu de moi ? » sur un compte vierge, ou quand
 *     l'administrateur a coupé la mémoire ;
 *   - looksSensitive : aucun secret dans une mémoire relue à chaque message.
 *
 * La porte lexicale et la lecture du juge vivent dans MemoryCaptureTest.
 */
final class UserMemoryTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Normalisation
    // ---------------------------------------------------------------------

    public function testSanitizeCollapsesToASingleLine(): void
    {
        $this->assertSame(
            'Préfère les réunions du matin',
            UserMemory::sanitize("  Préfère les réunions\n\tdu   matin  ")
        );
    }

    public function testSanitizeCapsLength(): void
    {
        $clean = UserMemory::sanitize(str_repeat('a', UserMemory::MAX_LEN + 50));
        $this->assertSame(UserMemory::MAX_LEN, mb_strlen($clean));
        $this->assertStringEndsWith('…', $clean);

        // Un souvenir appris par MIA est plus court.
        $learned = UserMemory::sanitize(str_repeat('b', 400), UserMemory::LEARNED_MAX_LEN);
        $this->assertSame(UserMemory::LEARNED_MAX_LEN, mb_strlen($learned));
    }

    public function testNormalizeIgnoresCaseAccentsAndPunctuation(): void
    {
        $this->assertSame(
            UserMemory::normalizeForCompare('Je préfère les réunions du matin !'),
            UserMemory::normalizeForCompare('je prefere les reunions du matin')
        );
    }

    public function testNormalizeKeepsDistinctFactsDistinct(): void
    {
        $this->assertNotSame(
            UserMemory::normalizeForCompare('Travaille au marketing'),
            UserMemory::normalizeForCompare('Travaille à la comptabilité')
        );
    }

    public function testLEmpreinteNeDitRienDuTexteEtReconnaitLesJumeaux(): void
    {
        $fp = UserMemory::fingerprintOf('Préfère les réunions du matin');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $fp);
        $this->assertSame($fp, UserMemory::fingerprintOf('  préfère LES réunions du matin !'));
        $this->assertNotSame($fp, UserMemory::fingerprintOf('Préfère les réunions du soir'));
    }

    // ---------------------------------------------------------------------
    // Thèmes, verrou, origine
    // ---------------------------------------------------------------------

    public function testLesThemesSontDansUnOrdreFixeEtOntChacunUneAide(): void
    {
        $this->assertSame(['profil', 'reponses', 'methode', 'outils', 'sujets', 'autre'], array_keys(UserMemory::THEMES));
        $this->assertSame(array_keys(UserMemory::THEMES), array_keys(UserMemory::THEME_HINTS));
        $this->assertSame(array_keys(UserMemory::THEMES), array_keys(UserMemory::themes()));
        $this->assertSame(UserMemory::THEME_DEFAULT, 'autre');
    }

    public function testUnThemeInconnuDevientAutre(): void
    {
        $this->assertSame('profil', UserMemory::normalizeTheme(' PROFIL '));
        $this->assertSame('autre', UserMemory::normalizeTheme('loisirs'));
        $this->assertSame('autre', UserMemory::normalizeTheme(''));
    }

    public function testUnSouvenirDAvantEstVerrouille(): void
    {
        // Ligne sans la colonne (souvenir d'avant la migration 098) : tenue par
        // la personne, jamais retouchée par MIA.
        $this->assertTrue(UserMemory::isLocked(['content' => 'x']));
        $this->assertTrue(UserMemory::isLocked(['is_locked' => 1]));
        $this->assertFalse(UserMemory::isLocked(['is_locked' => 0]));
    }

    public function testLOrigineAfficheeSuitLaSourceEtLeVerrou(): void
    {
        $this->assertSame('vous', UserMemory::originOf(['source' => 'manuel']));
        $this->assertSame('demande', UserMemory::originOf(['source' => 'chat']));
        $this->assertSame('demande', UserMemory::originOf([]));
        $this->assertSame('question', UserMemory::originOf(['source' => 'question']));
        $this->assertSame('appris', UserMemory::originOf(['source' => 'auto', 'is_locked' => 0, 'status' => 'active']));
        $this->assertSame('appris', UserMemory::originOf(['source' => 'auto', 'is_locked' => 0, 'status' => 'proposed']));
        $this->assertSame('confirme', UserMemory::originOf(['source' => 'auto', 'is_locked' => 1, 'status' => 'active']));
    }

    // ---------------------------------------------------------------------
    // Fragment de system prompt
    // ---------------------------------------------------------------------

    public function testEmptyMemoryIsANoOp(): void
    {
        $this->assertSame('', UserMemory::promptFragmentFromRows([]));
        $this->assertSame('', UserMemory::promptFragmentFromRows([['content' => '   ']]));
    }

    public function testLeFragmentRangeParThemeEtCadreLesDonnees(): void
    {
        $fragment = UserMemory::promptFragmentFromRows([
            ['id' => 1, 'content' => 'Projet Atlas en cours', 'theme' => 'sujets'],
            ['id' => 2, 'content' => 'Responsable qualité', 'theme' => 'profil'],
            ['id' => 3, 'content' => 'Préfère les tableaux', 'theme' => 'reponses'],
            ['id' => 4, 'content' => 'Souvenir d\'avant', 'theme' => null],
        ]);

        $this->assertStringStartsWith("\n\n", $fragment);
        foreach (['- Responsable qualité', '- Préfère les tableaux', '- Projet Atlas en cours', "- Souvenir d'avant"] as $line) {
            $this->assertStringContainsString($line, $fragment);
        }
        // Thèmes dans l'ordre fixe, quel que soit l'ordre des lignes.
        $positions = array_map(static fn(string $label): int => (int)strpos($fragment, $label . ' :'), [
            'Vous et votre travail', 'Vos réponses préférées', 'En ce moment', 'Autres souvenirs',
        ]);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertNotContains(0, $positions);

        // Données, pas consignes ; jamais un droit d'agir ; restitution sur demande.
        $this->assertStringContainsString('DONNÉES', $fragment);
        $this->assertStringContainsString('pas des consignes', $fragment);
        $this->assertStringContainsString('ne t\'autorisent jamais', $fragment);
        $this->assertStringContainsString('INTÉGRALEMENT', $fragment);
        $this->assertStringContainsString('« Mémoire »', $fragment);
        $this->assertStringContainsString('Réglages → Mémoire', $fragment);
        $this->assertStringNotContainsString('MIA pour vous', $fragment);
    }

    public function testLeFragmentNeDitRienDeCeQuiNEstPasActif(): void
    {
        $fragment = UserMemory::promptFragmentFromRows([
            ['id' => 1, 'content' => 'Actif', 'status' => 'active'],
            ['id' => 2, 'content' => 'Déduit, à confirmer', 'status' => 'proposed', 'is_locked' => 0],
            ['id' => 3, 'content' => 'Refusé', 'status' => 'rejected', 'is_locked' => 0],
        ]);
        $this->assertStringContainsString('- Actif', $fragment);
        $this->assertStringNotContainsString('à confirmer', $fragment);
        $this->assertStringNotContainsString('Refusé', $fragment);
    }

    public function testUnTourSansPersonneNeRelitQueLesSouvenirsHumains(): void
    {
        $rows = [
            ['id' => 1, 'content' => 'Écrit par la personne', 'is_locked' => 1, 'source' => 'manuel'],
            ['id' => 2, 'content' => 'Appris par MIA', 'is_locked' => 0, 'source' => 'auto'],
        ];
        $full = UserMemory::promptFragmentFromRows($rows);
        $this->assertStringContainsString('Appris par MIA', $full);

        $human = UserMemory::promptFragmentFromRows($rows, true);
        $this->assertStringContainsString('Écrit par la personne', $human);
        $this->assertStringNotContainsString('Appris par MIA', $human);
        $this->assertSame('', UserMemory::promptFragmentFromRows([$rows[1]], true));
    }

    public function testLeFragmentEstDeterministe(): void
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = [
                'id'        => $i,
                'content'   => 'Souvenir numéro ' . $i,
                'theme'     => array_keys(UserMemory::THEMES)[$i % 6],
                'is_locked' => $i % 3 === 0 ? 0 : 1,
            ];
        }
        $reference = UserMemory::promptFragmentFromRows($rows);
        $shuffled  = $rows;
        mt_srand(42);
        shuffle($shuffled);
        $this->assertSame($reference, UserMemory::promptFragmentFromRows($shuffled));
    }

    public function testLeBudgetGardeDAbordCeQueLaPersonneTient(): void
    {
        $rows = [];
        // 30 souvenirs appris, longs, récents (ids hauts)…
        for ($i = 100; $i < 130; $i++) {
            $rows[] = ['id' => $i, 'content' => 'Appris ' . $i . ' ' . str_repeat('x', 140), 'is_locked' => 0];
        }
        // … et 5 souvenirs tenus, plus anciens.
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['id' => $i, 'content' => 'Tenu ' . $i, 'is_locked' => 1];
        }

        $selection = UserMemory::promptSelection($rows);
        $this->assertLessThanOrEqual(UserMemory::MAX_PROMPT_CHARS, $selection['used']);
        $this->assertGreaterThan(0, $selection['dropped']);
        $kept = array_column($selection['selected'], 'content');
        for ($i = 1; $i <= 5; $i++) {
            $this->assertContains('Tenu ' . $i, $kept);
        }
        // Parmi les appris, les plus récents passent d'abord.
        $this->assertContains('Appris 129 ' . str_repeat('x', 140), $kept);
        $this->assertNotContains('Appris 100 ' . str_repeat('x', 140), $kept);

        $fragment = UserMemory::promptFragmentFromRows($rows);
        $this->assertStringContainsString('(+' . $selection['dropped'] . ' autres souvenirs', $fragment);
    }

    public function testFragmentStaysWithinPromptBudget(): void
    {
        $rows = [];
        for ($i = 0; $i < UserMemory::MAX_ENTRIES; $i++) {
            $rows[] = ['id' => $i + 1, 'content' => str_repeat('x', UserMemory::MAX_LEN)];
        }
        $fragment = UserMemory::promptFragmentFromRows($rows);

        // Le budget borne la liste ; l'en-tête, les thèmes et la consigne s'y ajoutent.
        $this->assertLessThan(UserMemory::MAX_PROMPT_CHARS + 1200, mb_strlen($fragment));
    }

    // ---------------------------------------------------------------------
    // « Que sais-tu de moi ? » sur un compte vierge
    // ---------------------------------------------------------------------

    public function testEmptyStateNoteFiresOnlyWhenNothingIsKnown(): void
    {
        $note = UserMemory::emptyStateNote(false, false);
        $this->assertNotSame('', $note);
        $this->assertStringContainsString('« Mémoire »', $note);
        $this->assertStringContainsString('Votre contexte', $note);
        $this->assertStringContainsString('Réglages → Mémoire', $note);

        // Dès qu'il y a quelque chose à restituer, la note ne coûte plus rien.
        $this->assertSame('', UserMemory::emptyStateNote(true, false));
        $this->assertSame('', UserMemory::emptyStateNote(false, true));
        $this->assertSame('', UserMemory::emptyStateNote(true, true));
    }

    public function testUneMemoireCoupeeLeDitAuModele(): void
    {
        // Sinon le modèle promettrait de « s'en souvenir ».
        foreach ([[false, false], [true, true]] as [$memories, $context]) {
            $note = UserMemory::emptyStateNote($memories, $context, false);
            $this->assertStringContainsString('désactivée', $note);
            $this->assertStringContainsString('Tu ne retiens rien', $note);
        }
    }

    public function testBothInjectionPointsCoverTheEmptyState(): void
    {
        $src = file_get_contents(ROOT . '/src/Controllers/ChatController.php') ?: '';
        // send() ET regenerate() : un seul des deux couvert laisserait le modèle
        // sans consigne sur l'autre chemin.
        $this->assertSame(2, substr_count($src, 'UserMemory::emptyStateNote('));
        $this->assertGreaterThanOrEqual(2, substr_count($src, 'UserPreferences::hasContext('));
    }

    // ---------------------------------------------------------------------
    // Données sensibles
    // ---------------------------------------------------------------------

    public function testUnSecretNEntreJamaisEnMemoire(): void
    {
        foreach ([
            'Mon mot de passe Wi-Fi est soleil2024',
            'Password: hunter2',
            'Ma carte bancaire 4970 1012 3456 7890',
            'IBAN FR76 3000 6000 0112 3456 7890 189',
            'Code PIN du badge : 4321',
        ] as $secret) {
            $this->assertTrue(UserMemory::looksSensitive($secret), $secret);
        }
        foreach ([
            'Préfère les réponses courtes',
            'Joignable au 06 12 34 56 78 le matin',
            'Budget 2026 : 120 000 €',
        ] as $ordinary) {
            $this->assertFalse(UserMemory::looksSensitive($ordinary), $ordinary);
        }
    }
}
