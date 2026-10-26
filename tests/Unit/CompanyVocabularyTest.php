<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Vocabulaire de l'entreprise (Organisation → Entreprise) : lu ligne à ligne,
 * envoyé en entier s'il est court, sinon réduit aux entrées que cite la
 * conversation — recalculé à chaque message.
 */
final class CompanyVocabularyTest extends TestCase
{
    private const SHORT = "- MAD : Mise À Disposition (contrat de travail)\n- CRA : Compte Rendu d'Activité\n- TJM : Taux Journalier Moyen";

    private static function source(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    /** Un glossaire long : les trois entrées utiles noyées dans cent vingt autres. */
    private static function long(): string
    {
        $text = "## RH\nNos clients sont appelés des partenaires.\n" . self::SHORT . "\n";
        for ($i = 0; $i < 120; $i++) {
            $text .= "- SIGLE$i : définition numéro $i, assez longue pour remplir le glossaire\n";
        }

        return $text . "- R&D : Recherche et développement\n";
    }

    public function testLectureLigneALigne(): void
    {
        $parsed = CompanyVocabulary::parse("## RH\n- MAD : Mise À Disposition\n* KPI = indicateur\n1. OKR → objectifs\nESN\tEntreprise de services\nNos clients sont des partenaires.\nUne phrase très longue de plus de six mots avant : le deux-points");

        $this->assertSame(['MAD', 'KPI', 'OKR', 'ESN'], array_column($parsed['entries'], 'term'));
        $this->assertSame('- KPI : indicateur', $parsed['entries'][1]['line'], 'Ligne réécrite sous une forme unique.');
        $this->assertSame(['Nos clients sont des partenaires.', 'Une phrase très longue de plus de six mots avant : le deux-points'], $parsed['free']);
    }

    public function testCourtEnvoyeEnEntier(): void
    {
        $sel = CompanyVocabulary::select(self::SHORT, 'bonjour');
        $this->assertSame('all', $sel['mode']);
        $this->assertSame(3, $sel['total']);

        $fragment = CompanyVocabulary::fragmentFrom(self::SHORT, '');
        $this->assertSame("\n\nVocabulaire et acronymes de l'entreprise :\n" . self::SHORT, $fragment,
            'Même texte qu\'avant le déménagement : un glossaire court ne change pas de forme.');
        $this->assertSame('', CompanyVocabulary::fragmentFrom('   ', 'x'));
    }

    public function testLongReduitAuxEntreesCitees(): void
    {
        $long = self::long();
        $this->assertGreaterThan(CompanyVocabulary::FULL_CHARS, mb_strlen($long));

        $cas = [
            'quel tjm pour une mission en mad ?' => ['MAD', 'TJM'],
            'Le taux journalier moyen'           => ['TJM'],
            'les CRAs du mois'                   => ['CRA'],
            'notre r&d avance'                   => ['R&D'],
            'un crayon et une madeleine'         => [],
            'rien à voir'                        => [],
        ];
        foreach ($cas as $contexte => $attendus) {
            $sel = CompanyVocabulary::select($long, $contexte);
            $this->assertSame('selected', $sel['mode']);
            $this->assertSame($attendus, $sel['terms'], $contexte);
        }

        $fragment = CompanyVocabulary::fragmentFrom($long, 'quel TJM ?');
        $this->assertStringContainsString("l'entrée (sur 124) qui concerne cette conversation", $fragment);
        $this->assertStringContainsString('les 2 entrées (sur 124)', CompanyVocabulary::fragmentFrom($long, 'TJM et MAD'));
        $this->assertStringContainsString('- TJM : Taux Journalier Moyen', $fragment);
        $this->assertStringContainsString('Nos clients sont appelés des partenaires.', $fragment, 'Les lignes libres sont du contexte général.');
        $this->assertStringNotContainsString('SIGLE7', $fragment);
        $this->assertStringNotContainsString('## RH', $fragment);
        $this->assertStringContainsString('demande son sens plutôt que de l\'inventer', $fragment);
        $this->assertLessThan(mb_strlen($long) / 5, mb_strlen($fragment));
    }

    public function testAucuneEntreeNiLigneLibre(): void
    {
        $long = str_replace("## RH\nNos clients sont appelés des partenaires.\n", '', self::long());
        $fragment = CompanyVocabulary::fragmentFrom($long, 'bonjour');
        $this->assertStringContainsString('glossaire de 124 termes ; aucun ne figure dans cette conversation', $fragment);
    }

    public function testCorrespondanceEnMotsEntiers(): void
    {
        $haystack = ' ' . CompanyVocabulary::normalize('Un crayon, des CRAs et un taux journalier moyen élevé.') . ' ';
        $this->assertTrue(CompanyVocabulary::mentioned(['term' => 'CRA', 'definition' => 'x'], $haystack), 'Pluriel accepté.');
        $this->assertFalse(CompanyVocabulary::mentioned(['term' => 'CRAY', 'definition' => 'x'], $haystack), 'Pas dans « crayon ».');
        $this->assertTrue(CompanyVocabulary::mentioned(['term' => 'TJM', 'definition' => 'Taux journalier moyen (prix d\'un jour)'], $haystack),
            'Le début de la définition suffit.');
        $this->assertFalse(CompanyVocabulary::mentioned(['term' => 'X', 'definition' => 'élevé'], $haystack),
            'Un terme d\'une lettre et une définition d\'un mot ne déclenchent rien.');
        $this->assertSame('r d eleve', CompanyVocabulary::normalize('R&D élevé'));
    }

    public function testContexte(): void
    {
        $ctx = CompanyVocabulary::contextFrom('question en cours', [
            ['content' => 'ancien message'],
            ['content' => '⚠️ Erreur du modèle'],
            ['content' => 'réponse récente'],
        ], 'Tu aides sur les CRA.');

        $this->assertStringStartsWith('Tu aides sur les CRA.', $ctx);
        $this->assertStringEndsWith('question en cours', $ctx, 'Le plus récent en dernier : il reste quand il faut couper.');
        $this->assertStringNotContainsString('Erreur du modèle', $ctx);
        $this->assertLessThanOrEqual(CompanyVocabulary::MAX_CONTEXT_CHARS, mb_strlen(CompanyVocabulary::contextFrom(str_repeat('a', 50000))));
    }

    public function testSaisieBornee(): void
    {
        $this->assertSame("a\n\nb", CompanyVocabulary::sanitize("a\r\n\r\n\r\n\x07b  "));
        $this->assertSame(CompanyVocabulary::MAX_CHARS, mb_strlen(CompanyVocabulary::sanitize(str_repeat('é', CompanyVocabulary::MAX_CHARS + 10))));
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    /** Les trois prompts (envoi, régénération, présentation) passent par le vocabulaire sélectif. */
    public function testInjecteAuxTroisSites(): void
    {
        $chat = self::source('src/Controllers/ChatController.php');
        $this->assertSame(3, substr_count($chat, 'CompanyVocabulary::promptFragment('));
        $this->assertStringNotContainsString("Settings::get('common_vocabulary'", $chat);
        $this->assertStringContainsString("CompanyVocabulary::contextFrom(\$content, \$history, (string)(\$profile['system_prompt'] ?? ''))", $chat);
        $this->assertStringContainsString("CompanyVocabulary::contextFrom('', \$history, (string)(\$profile['system_prompt'] ?? ''))", $chat);

        // Juste après l'entreprise, avant les règles communes.
        foreach (['function runSend(', 'function regenerate('] as $method) {
            $body    = substr($chat, (int)strpos($chat, $method), 60000);
            $company = strpos($body, '$systemPrompt .= CompanyProfile::promptFragment();');
            $vocab   = strpos($body, '$systemPrompt .= CompanyVocabulary::promptFragment(');
            $rules   = strpos($body, '$systemPrompt .= $this->capabilityGuardrails();');
            $this->assertLessThan($vocab, $company, $method);
            $this->assertLessThan($rules, $vocab, $method);
        }
    }

    /** Il a quitté Modèles IA → Modèles : ni champ là-bas, ni écriture par son ancien handler. */
    public function testDemenageSurLaPageEntreprise(): void
    {
        $models = self::source('templates/admin/llms/models.php');
        $this->assertStringNotContainsString('name="common_vocabulary"', $models);
        $this->assertStringContainsString('/admin/company#vocabulaire', $models);

        $admin = self::source('src/Controllers/AdminController.php');
        $start = (int)strpos($admin, 'public function saveLlmDefaults(');
        $body  = substr($admin, $start, (int)strpos($admin, 'public function saveLlmModel(') - $start);
        $this->assertStringNotContainsString('common_vocabulary', $body);
        $this->assertStringNotContainsString('CompanyVocabulary', $body);

        $company = self::source('templates/admin/company.php');
        $this->assertStringContainsString('name="<?= CompanyVocabulary::SETTING ?>"', $company);
        $this->assertSame('common_vocabulary', CompanyVocabulary::SETTING, 'Même clé : rien à migrer.');
    }
}
