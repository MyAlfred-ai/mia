<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Points de départ du prompt système d'un assistant (bibliothèque + aide IA).
 *
 * Régressions gardées :
 *  - un modèle de la bibliothèque qui quitterait le squelette commun : le
 *    créateur apprend la structure en lisant UN exemple, un modèle différent
 *    la lui désapprendrait ;
 *  - la consigne de génération qui cesserait d'imposer ce même squelette :
 *    les deux voies produiraient des prompts de familles différentes ;
 *  - une sortie de modèle recopiée telle quelle (fence, préambule) dans le
 *    champ — l'utilisateur l'enregistrerait sans le voir ;
 *  - le motif des [crochets] qui divergerait entre PHP et le JS de la fiche
 *    (sélection du premier marqueur après insertion) ;
 *  - la version ANGLAISE qui manquerait un modèle, un champ ou une section,
 *    retomberait en silence sur le français, ou ferait rédiger par le modèle
 *    un prompt dont les titres ne sont pas ceux de la langue de l'interface.
 */
final class PromptStartersTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    /** Corps des sections d'un prompt, dans l'ordre (sans le texte avant le premier titre). @return list<string> */
    private static function corpsDesSections(string $prompt): array
    {
        return array_slice(preg_split('/^## .+$/mu', $prompt), 1);
    }

    private static function lire(string $chemin): string
    {
        $abs = ROOT . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    // ---------- Bibliothèque ----------

    public function testChaqueModeleEstCompletEtSuitLeSquelette(): void
    {
        $all = PromptStarters::all();
        $this->assertGreaterThanOrEqual(6, count($all), 'Bibliothèque trop maigre.');

        $keys = [];
        foreach ($all as $st) {
            $keys[] = $st['key'];
            foreach (['label', 'tagline', 'icon', 'accent', 'prompt'] as $champ) {
                $this->assertNotSame('', trim((string)($st[$champ] ?? '')), "{$st['key']} : champ {$champ} vide");
            }
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $st['accent'], "{$st['key']} : accent non hexadécimal");
            $this->assertTrue(PromptStarters::followsSkeleton($st['prompt']), "{$st['key']} : ne suit pas le squelette commun");
            $this->assertStringStartsWith('## ' . PromptStarters::SECTIONS[0], trim($st['prompt']), "{$st['key']} : ne commence pas par le Rôle");
            $this->assertMatchesRegularExpression('/^## Rôle\n(Tu es|Tu )/mu', $st['prompt'], "{$st['key']} : le Rôle doit s'adresser à l'assistant (« Tu es … »)");
            if (isset($st['temperature'])) {
                $this->assertGreaterThanOrEqual(0.0, $st['temperature']);
                $this->assertLessThanOrEqual(2.0, $st['temperature']);
            }
            // Aucun titre hors squelette : un « ## Exemples » casserait la
            // promesse « six sections, toujours les mêmes ».
            preg_match_all('/^## (.+)$/mu', $st['prompt'], $m);
            $this->assertSame(PromptStarters::SECTIONS, $m[1], "{$st['key']} : titres hors squelette ou désordonnés");
            // Une section vide serait un trou que le créateur prendrait pour un oubli.
            $sections = preg_split('/^## .+$/mu', $st['prompt']);
            foreach (array_slice($sections, 1) as $i => $corps) {
                $this->assertNotSame('', trim($corps), "{$st['key']} : section « " . PromptStarters::SECTIONS[$i] . " » vide");
            }
        }
        $this->assertSame($keys, array_unique($keys), 'Clés de modèles en double.');
        $this->assertSame($keys, PromptStarters::keys());
    }

    public function testLesModelesRenvoientAuxDocumentsDeReference(): void
    {
        // La section Sources existe pour ça : un assistant sans cette consigne
        // ignore le RAG qu'on lui a pourtant branché.
        foreach (PromptStarters::all() as $st) {
            $this->assertMatchesRegularExpression(
                '/## Sources\n.*documents? (de référence|fournis)/su',
                $st['prompt'],
                "{$st['key']} : la section Sources ne mentionne pas les documents de référence"
            );
        }
    }

    // ---------- Bibliothèque anglaise ----------

    public function testLaBibliothequeAnglaiseEstCompleteEtSuitLeSqueletteAnglais(): void
    {
        $fr = PromptStarters::all('fr');
        $en = PromptStarters::all('en');
        $this->assertCount(count($fr), $en, 'Un modèle manque en anglais.');
        $this->assertSame(array_column($fr, 'key'), array_column($en, 'key'), 'Mêmes clés, même ordre dans les deux langues.');
        $this->assertCount(count(PromptStarters::SECTIONS), PromptStarters::SECTIONS_EN);

        foreach ($en as $i => $st) {
            $key = $st['key'];
            // Réglages communs : une seule source, ils ne divergent pas d'une langue à l'autre.
            foreach (['icon', 'accent', 'temperature'] as $champ) {
                $this->assertSame($fr[$i][$champ] ?? null, $st[$champ] ?? null, "{$key} : {$champ} diffère en anglais");
            }
            // Textes RÉDIGÉS : présents, et pas un repli silencieux sur le français.
            foreach (['label', 'tagline', 'description', 'prompt'] as $champ) {
                $this->assertNotSame('', trim((string)($st[$champ] ?? '')), "{$key} : champ {$champ} vide en anglais");
                $this->assertNotSame($fr[$i][$champ], $st[$champ], "{$key} : {$champ} non traduit (retombé sur le français)");
                $this->assertDoesNotMatchRegularExpression('/[éèêàùçôîœ«»]/u', $st[$champ], "{$key} : {$champ} porte encore du français");
            }

            $prompt = $st['prompt'];
            $this->assertTrue(PromptStarters::followsSkeleton($prompt, 'en'), "{$key} : ne suit pas le squelette anglais");
            $this->assertFalse(PromptStarters::followsSkeleton($prompt, 'fr'), "{$key} : titres français dans le prompt anglais");
            preg_match_all('/^## (.+)$/mu', $prompt, $m);
            $this->assertSame(PromptStarters::SECTIONS_EN, $m[1], "{$key} : titres hors squelette anglais ou désordonnés");
            $this->assertMatchesRegularExpression('/^## Role\nYou /mu', $prompt, "{$key} : le Role doit s'adresser à l'assistant (« You … »)");
            foreach (self::corpsDesSections($prompt) as $j => $corps) {
                $this->assertNotSame('', trim($corps), "{$key} : section « " . PromptStarters::SECTIONS_EN[$j] . " » vide");
            }
            // La section Sources branche le RAG, comme en français.
            $this->assertMatchesRegularExpression(
                '/## Sources\n.*(reference documents?|documents provided)/su',
                $prompt,
                "{$key} : la section Sources ne mentionne pas les documents de référence"
            );

            // Marqueurs à compléter : aucun perdu à la rédaction, tous reconnus
            // par le motif partagé avec le JS (sélection du premier).
            $nbFr = preg_match_all(PromptStarters::PLACEHOLDER_PATTERN, $fr[$i]['prompt']);
            $nbEn = preg_match_all(PromptStarters::PLACEHOLDER_PATTERN, $prompt);
            $this->assertGreaterThanOrEqual($nbFr, $nbEn, "{$key} : marqueur [à compléter] perdu en anglais");
            preg_match_all('/\[[^\[\]\n]*\]/u', $prompt, $crochets);
            foreach ($crochets[0] as $crochet) {
                if ($crochet === '[?]') {
                    continue; // « marque-le [?] » : une consigne, pas un champ à remplir.
                }
                $this->assertSame(1, preg_match(PromptStarters::PLACEHOLDER_PATTERN, $crochet), "{$key} : marqueur {$crochet} non reconnu");
            }
        }
    }

    public function testLaLangueSuitLInterfaceEtRetombeSurLeFrancais(): void
    {
        $this->assertSame('fr', PromptStarters::language());
        $this->assertSame(PromptStarters::SECTIONS, PromptStarters::sections());
        $this->assertSame(PromptStarters::all('fr'), PromptStarters::all());

        I18n::use('en');
        $this->assertSame('en', PromptStarters::language());
        $this->assertSame(PromptStarters::SECTIONS_EN, PromptStarters::sections());
        $this->assertSame(PromptStarters::all('en'), PromptStarters::all());
        $this->assertSame('Subject-matter expert', PromptStarters::find('expert')['label'] ?? null);
        $this->assertSame('Expert métier', PromptStarters::find('expert', 'fr')['label'] ?? null, 'Une locale explicite prime sur l\'interface.');
        $this->assertTrue(PromptStarters::followsSkeleton(PromptStarters::skeleton()));
        $this->assertStringStartsWith('## Role', PromptStarters::skeleton());
        $this->assertSame(PromptStarters::structureDirective('en'), PromptStarters::structureDirective(), 'La directive (aussi lue par creer_assistant) suit l\'interface.');
        $this->assertSame(PromptStarters::keys(), array_column(PromptStarters::all(), 'key'));

        // Locale non rédigée : le français, jamais un modèle vide.
        $this->assertSame('fr', PromptStarters::language('de'));
        $this->assertSame(PromptStarters::all('fr'), PromptStarters::all('de'));
        $this->assertSame(PromptStarters::SECTIONS, PromptStarters::sections('de'));
    }

    public function testLesMessagesDErreurSuiventLaLangue(): void
    {
        $cas = [
            [['mode' => 'inventer'], 'Mode inconnu.', 'Unknown mode.'],
            [['mode' => 'rediger', 'brief' => ' '], 'Décrivez', 'Describe the assistant'],
            [['mode' => 'ameliorer', 'existing' => 'court'], 'pas encore de prompt à améliorer', 'no prompt to improve'],
        ];
        foreach ($cas as [$input, $fr, $en]) {
            foreach (['fr' => $fr, 'en' => $en] as $locale => $attendu) {
                I18n::use($locale);
                try {
                    PromptStarters::normalizeRequest($input);
                    $this->fail('Demande invalide acceptée : ' . json_encode($input));
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString($attendu, $e->getMessage(), "message {$locale}");
                }
            }
            // Locale explicite : prime sur l'interface.
            I18n::use('fr');
            try {
                PromptStarters::normalizeRequest($input, 'en');
                $this->fail('Demande invalide acceptée.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($en, $e->getMessage());
            }
        }
    }

    public function testFindEtSkeleton(): void
    {
        $this->assertNull(PromptStarters::find('inexistant'));
        $expert = PromptStarters::find('expert');
        $this->assertSame('expert', $expert['key'] ?? null);

        $skeleton = PromptStarters::skeleton();
        $this->assertTrue(PromptStarters::followsSkeleton($skeleton));
        $this->assertStringNotContainsString('Tu es', $skeleton, 'Le placeholder est un squelette VIDE, pas un modèle de plus.');
    }

    public function testFollowsSkeletonExigeLesSixTitresDansLOrdre(): void
    {
        $ok = implode("\n", array_map(static fn(string $s): string => "## {$s}\nx", PromptStarters::SECTIONS));
        $this->assertTrue(PromptStarters::followsSkeleton($ok));
        $this->assertFalse(PromptStarters::followsSkeleton(str_replace('## Mission', '## But', $ok)), 'Titre manquant accepté.');

        $inverse = array_reverse(PromptStarters::SECTIONS);
        $this->assertFalse(PromptStarters::followsSkeleton(implode("\n", array_map(static fn(string $s): string => "## {$s}\nx", $inverse))), 'Ordre ignoré.');
        $this->assertFalse(PromptStarters::followsSkeleton(''));
    }

    public function testLeMotifDesCrochetsEstLeMemeEnPhpEtEnJs(): void
    {
        $this->assertSame(1, preg_match(PromptStarters::PLACEHOLDER_PATTERN, "Tu es le rédacteur de [nom de l'organisation]."));
        $this->assertSame(1, preg_match(PromptStarters::PLACEHOLDER_PATTERN, 'You are the writer for [organisation name].'));
        $this->assertSame(0, preg_match(PromptStarters::PLACEHOLDER_PATTERN, 'marque-le [?] avec la question'), 'Un [?] n\'est pas un champ à remplir.');

        $gabarit = self::lire('templates/admin/profiles/edit.php');
        $this->assertSame(1, preg_match('~const PLACEHOLDER_RE\s*=\s*(/.+?/);~', $gabarit, $m), 'Motif JS introuvable dans la fiche.');
        $php = preg_replace('/u$/', '', PromptStarters::PLACEHOLDER_PATTERN);
        $this->assertSame($php, $m[1], 'Le motif JS des [crochets] a divergé de PromptStarters::PLACEHOLDER_PATTERN.');
    }

    // ---------- Requête ----------

    public function testNormalizeRequestRefuseUnModeInconnu(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PromptStarters::normalizeRequest(['mode' => 'inventer', 'brief' => 'x']);
    }

    public function testRedigerExigeUnBriefOuUnContexte(): void
    {
        try {
            PromptStarters::normalizeRequest(['mode' => 'rediger', 'brief' => '   ']);
            $this->fail('Un brief vide sans nom ni description doit être refusé.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Décrivez', $e->getMessage());
        }
        // Un nom seul suffit : le modèle déduit le rôle.
        $r = PromptStarters::normalizeRequest(['mode' => 'rediger', 'name' => 'Assistant RH']);
        $this->assertSame('', $r['brief']);
        $this->assertSame('Assistant RH', $r['name']);
    }

    public function testAmeliorerExigeUnPromptExistant(): void
    {
        try {
            PromptStarters::normalizeRequest(['mode' => 'ameliorer', 'existing' => 'court']);
            $this->fail('Un prompt trop court doit être refusé.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('améliorer', $e->getMessage());
        }
        $r = PromptStarters::normalizeRequest(['mode' => 'ameliorer', 'existing' => str_repeat('Tu es utile. ', 5)]);
        $this->assertSame('ameliorer', $r['mode']);
    }

    public function testLesEntreesSontBorneesEtNettoyees(): void
    {
        $r = PromptStarters::normalizeRequest([
            'mode'        => 'rediger',
            'brief'       => "ligne 1\r\nligne 2\x00\x07 fin " . str_repeat('a', PromptStarters::BRIEF_MAX + 50),
            'name'        => "Nom\navec\tretours " . str_repeat('n', 500),
            'description' => str_repeat('d', PromptStarters::DESC_MAX + 10),
            'existing'    => str_repeat('e', PromptStarters::EXISTING_MAX + 10),
        ]);
        $this->assertStringStartsWith("ligne 1\nligne 2 fin ", $r['brief'], 'CRLF normalisé, contrôles retirés, retours conservés.');
        $this->assertLessThanOrEqual(PromptStarters::BRIEF_MAX, mb_strlen($r['brief']));
        $this->assertStringStartsWith('Nom avec retours ', $r['name'], 'Le nom tient sur une ligne.');
        $this->assertLessThanOrEqual(PromptStarters::NAME_MAX, mb_strlen($r['name']));
        $this->assertSame(PromptStarters::DESC_MAX, mb_strlen($r['description']));
        $this->assertSame(PromptStarters::EXISTING_MAX, mb_strlen($r['existing']));
    }

    // ---------- Prompts de génération ----------

    public function testLaConsigneImposeLeSqueletteDansLesDeuxModes(): void
    {
        foreach (PromptStarters::MODES as $mode) {
            $sys = PromptStarters::generationSystemPrompt($mode);
            foreach (PromptStarters::SECTIONS as $section) {
                $this->assertStringContainsString("## {$section}", $sys, "{$mode} : section « {$section} » absente de la consigne");
            }
            $this->assertStringContainsString('UNIQUEMENT', $sys, "{$mode} : la consigne doit interdire tout texte autour du prompt");
            $this->assertStringContainsString('bloc de code', $sys);
            $this->assertStringContainsString('crochets', $sys, "{$mode} : les inconnues se marquent entre crochets, jamais inventées");
            $this->assertStringContainsString('droits', $sys, "{$mode} : un prompt généré ne doit pas élargir les droits de l'assistant");
        }
        $this->assertStringContainsString('ne retires aucune consigne', PromptStarters::generationSystemPrompt('ameliorer'));
        $this->assertStringContainsString('JAMAIS une valeur inventée', PromptStarters::generationSystemPrompt('rediger'));
    }

    public function testLeMessageUtilisateurPorteLeBriefEtLeContexte(): void
    {
        $r = PromptStarters::normalizeRequest([
            'mode' => 'rediger', 'brief' => 'Aide les commerciaux.', 'name' => 'Coach vente', 'description' => 'Prépare les RDV.',
        ]);
        $u = PromptStarters::generationUserPrompt($r);
        $this->assertStringContainsString('Coach vente', $u);
        $this->assertStringContainsString('Prépare les RDV.', $u);
        $this->assertStringContainsString('Aide les commerciaux.', $u);
        $this->assertStringNotContainsString('Prompt actuel', $u);

        $r2 = PromptStarters::normalizeRequest(['mode' => 'ameliorer', 'existing' => 'Tu es un assistant qui aide les gens.', 'brief' => 'Vouvoiement.']);
        $u2 = PromptStarters::generationUserPrompt($r2);
        $this->assertStringContainsString('Prompt actuel', $u2);
        $this->assertStringContainsString('Tu es un assistant qui aide les gens.', $u2);
        $this->assertStringContainsString('Consigne de l\'auteur : Vouvoiement.', $u2);
        $this->assertStringContainsString('(non renseigné)', $u2);
    }

    // ---------- Nettoyage de la sortie ----------

    public function testCleanOutputRetireFenceEtPreambule(): void
    {
        $corps = "## Rôle\nTu es X.\n\n## Mission\nY.";
        $this->assertSame($corps, PromptStarters::cleanOutput("```markdown\n{$corps}\n```"));
        $this->assertSame($corps, PromptStarters::cleanOutput("Voici le prompt système demandé :\n\n{$corps}\n"));
        $this->assertSame($corps, PromptStarters::cleanOutput("\r\n" . str_replace("\n", "\r\n", $corps) . "\r\n"));
        $this->assertSame('', PromptStarters::cleanOutput("   \n  "));
    }

    public function testCleanOutputLaisseIntactUnTexteSansSquelette(): void
    {
        // Pas de « ## Rôle » proche du début : rien à couper, on ne devine pas.
        $libre = "Tu es un assistant qui répond en vers.\nReste bref.";
        $this->assertSame($libre, PromptStarters::cleanOutput($libre));

        // Un « ## Rôle » loin dans le texte (après 160 caractères) n'est pas
        // un préambule : le début fait partie du prompt.
        $long = str_repeat('Contexte. ', 30) . "\n## Rôle\nTu es X.";
        $this->assertSame(trim($long), PromptStarters::cleanOutput($long));
    }

    public function testCleanOutputRameneLesTitresAuLibelleSeul(): void
    {
        // Constaté en recette : le modèle recopiait l'explication dans le titre.
        $sale = "## Rôle — qui est l'assistant, pour qui il travaille\nTu es X.\n\n## 2. Mission\nY.\n\n## Ton et format : registre\nZ.\n\n## Règles et limites\n- a\n\n## Sources\n- b\n\n## Cas limites\n- c";
        $propre = PromptStarters::cleanOutput($sale);
        preg_match_all('/^## (.+)$/mu', $propre, $m);
        $this->assertSame(PromptStarters::SECTIONS, $m[1]);
        $this->assertStringContainsString("## Rôle\nTu es X.", $propre);
        // Un titre hors squelette n'est pas touché (on ne devine pas).
        $this->assertStringContainsString('## Exemples — à garder', PromptStarters::cleanOutput("## Rôle\nx\n\n## Exemples — à garder\ny"));
    }

    public function testLaDirectiveDonneLesTitresSeulsSurLeurLigne(): void
    {
        // Libellé et explication sur la même ligne → recopiés ensemble dans le titre.
        foreach (PromptStarters::MODES as $mode) {
            $sys = PromptStarters::generationSystemPrompt($mode);
            foreach (PromptStarters::SECTIONS as $section) {
                $this->assertMatchesRegularExpression('/^## ' . preg_quote($section, '/') . '$/mu', $sys, "{$mode} : « ## {$section} » doit être seul sur sa ligne");
            }
        }
    }

    public function testLaConsigneExigeLaLangueDeLInterfaceEtSesTitres(): void
    {
        // Français : le prompt produit est en français, même sur un brief
        // écrit dans une autre langue (l'ancienne règle « rédige dans la
        // langue du brief » mêlait texte anglais et titres français).
        $rediger = PromptStarters::generationSystemPrompt('rediger');
        $this->assertStringContainsString('rédige le prompt en français', $rediger);
        $this->assertStringNotContainsString('rédige dans cette langue', $rediger);
        $this->assertStringContainsString('traduis-le alors fidèlement', PromptStarters::generationSystemPrompt('ameliorer'));

        I18n::use('en');
        foreach (PromptStarters::MODES as $mode) {
            $sys = PromptStarters::generationSystemPrompt($mode);
            $this->assertSame($sys, PromptStarters::generationSystemPrompt($mode, 'en'), "{$mode} : la consigne suit l'interface");
            foreach (PromptStarters::SECTIONS_EN as $section) {
                $this->assertMatchesRegularExpression('/^## ' . preg_quote($section, '/') . '$/mu', $sys, "{$mode} : « ## {$section} » doit être seul sur sa ligne");
            }
            foreach (['Rôle', 'Ton et format', 'Règles et limites', 'Cas limites'] as $titreFr) {
                $this->assertStringNotContainsString("## {$titreFr}", $sys, "{$mode} : titre français dans la consigne anglaise");
            }
            $this->assertStringContainsString('ANGLAIS', $sys, "{$mode} : la langue du prompt produit doit être exigée");
            $this->assertStringContainsString('titres anglais', $sys);
            $this->assertStringContainsString('« You … »', $sys, "{$mode} : le prompt anglais s'adresse à l'assistant en « You »");
            $this->assertStringNotContainsString('tutoiement', $sys, "{$mode} : le tutoiement n'a pas de sens en anglais");
            // Les garde-fous de méthode sont les mêmes dans les deux langues.
            $this->assertStringContainsString('UNIQUEMENT', $sys);
            $this->assertStringContainsString('crochets', $sys);
            $this->assertStringContainsString('droits', $sys);
            $this->assertStringContainsString('[organisation name]', $sys, "{$mode} : exemple de marqueur en anglais");
        }
        $this->assertStringContainsString('même si le brief', PromptStarters::generationSystemPrompt('rediger'));
        $this->assertStringContainsString('traduis alors fidèlement', PromptStarters::generationSystemPrompt('ameliorer'));
        $this->assertStringContainsString('[escalation channel]', PromptStarters::generationSystemPrompt('ameliorer'));

        // Le message utilisateur rappelle la langue en dernier, juste avant la réponse attendue.
        foreach ([
            ['mode' => 'rediger', 'brief' => 'Aide les commerciaux.'],
            ['mode' => 'ameliorer', 'existing' => "## Rôle\nTu es un assistant qui aide les gens."],
        ] as $input) {
            $u = PromptStarters::generationUserPrompt(PromptStarters::normalizeRequest($input));
            $this->assertMatchesRegularExpression('/Langue attendue : anglais.*## Role, ## Mission.*\n\nPrompt (système|amélioré) :$/su', $u);
        }

        I18n::use('fr');
        $u = PromptStarters::generationUserPrompt(PromptStarters::normalizeRequest(['mode' => 'rediger', 'brief' => 'x']));
        $this->assertStringNotContainsString('Langue attendue', $u, 'Rien à rappeler en français.');
    }

    public function testCleanOutputReconnaitLesTitresAnglaisSansLesTraduire(): void
    {
        $corps = "## Role\nYou are X.\n\n## Mission\nY.";
        $this->assertSame($corps, PromptStarters::cleanOutput("```markdown\n{$corps}\n```"));
        $this->assertSame($corps, PromptStarters::cleanOutput("Here is the system prompt you asked for:\n\n{$corps}\n"));

        // Titres alourdis, numérotés ou en casse de titre anglaise → libellé exact.
        $sale = "## Role — who the assistant is\nYou are X.\n\n## 2. Mission\nY.\n\n## Tone And Format: register\nZ.\n\n## RULES AND LIMITS\n- a\n\n## Sources - documents\n- b\n\n## Edge Cases\n- c";
        $propre = PromptStarters::cleanOutput($sale);
        preg_match_all('/^## (.+)$/mu', $propre, $m);
        $this->assertSame(PromptStarters::SECTIONS_EN, $m[1]);
        $this->assertTrue(PromptStarters::followsSkeleton($propre, 'en'));

        // Jamais traduit : un titre reste dans la langue où le modèle l'a écrit.
        $this->assertSame("## Rôle\nTu es X.", PromptStarters::cleanOutput("## RÔLE — qui\nTu es X."));
        $mixte = PromptStarters::cleanOutput("## Rôle\nx\n\n## Tone and format\ny");
        $this->assertStringContainsString('## Rôle', $mixte);
        $this->assertStringContainsString('## Tone and format', $mixte);
        $this->assertStringNotContainsString('## Role', $mixte);
        $this->assertStringNotContainsString('## Ton et format', $mixte);

        // Un trait d'union collé n'est pas une explication ; un titre hors squelette reste intact.
        $this->assertStringContainsString('## Role-play scenarios', PromptStarters::cleanOutput("## Role\nx\n\n## Role-play scenarios\ny"));
        $this->assertStringContainsString('## Examples — keep', PromptStarters::cleanOutput("## Role\nx\n\n## Examples — keep\ny"));

        // Un « ## Role » loin du début n'est pas précédé d'un préambule.
        $long = str_repeat('Context. ', 30) . "\n## Role\nYou are X.";
        $this->assertSame(trim($long), PromptStarters::cleanOutput($long));
    }

    public function testCleanOutputBorneLaLongueur(): void
    {
        $out = PromptStarters::cleanOutput("## Rôle\n" . str_repeat('x', PromptStarters::OUTPUT_MAX + 500));
        $this->assertSame(PromptStarters::OUTPUT_MAX, mb_strlen($out));
    }

    // ---------- Câblage ----------

    public function testLaRouteEstDeclareeAvantLesRoutesParametrees(): void
    {
        $routes = self::lire('public/index.php');
        $litterale = "post('/admin/profiles/prompt/generate'";
        $a = strpos($routes, $litterale);
        $b = strpos($routes, "post('/admin/profiles/{id}'");
        $this->assertNotFalse($a, 'Route de rédaction absente.');
        $this->assertNotFalse($b);
        $this->assertLessThan($b, $a, 'La route littérale doit précéder /admin/profiles/{id}.');
        $this->assertStringContainsString("[AdminController::class, 'generateProfilePrompt']", $routes);
    }

    public function testLEndpointVerifieDroitsQuotaEtJournaliseLUsage(): void
    {
        $ctrl = self::lire('src/Controllers/AdminController.php');
        $pos  = strpos($ctrl, 'public function generateProfilePrompt(');
        $this->assertNotFalse($pos, 'generateProfilePrompt() introuvable.');
        $fin  = strpos($ctrl, 'public function createProfile(', $pos);
        $corps = substr($ctrl, $pos, $fin - $pos);

        // Qui crée ou modifie des assistants (Personnes → Rôles).
        $this->assertStringContainsString("AuthMiddleware::requireAnyPermission('assistants.create', 'assistants.edit')", $corps);
        $this->assertStringContainsString('PromptStarters::normalizeRequest($_POST)', $corps);
        // Décision produit : facturé au créateur → quota AVANT l'appel, usage APRÈS.
        $this->assertStringContainsString('CreditQuota::isExceeded', $corps);
        // Par le compteur unique du grand livre (calcul + persistance), source prompt_assist.
        $this->assertStringContainsString('UsageMeter::llm(', $corps);
        $this->assertStringContainsString('UsageSource::PROMPT_ASSIST', $corps);
        // Toujours le LLM par défaut de l'app, jamais celui de la fiche.
        $this->assertStringContainsString('SemanticChunker::resolveChunkingLlm()', $corps);
        $this->assertStringNotContainsString("\$_POST['default_llm']", $corps);
        // Erreur d'adaptateur : présentée, jamais renvoyée brute.
        $this->assertStringContainsString('LlmErrorPresenter::parse', $corps);
        $this->assertStringContainsString('PromptStarters::cleanOutput', $corps);
        // La fiche rattachée à l'usage est re-vérifiée : pas d'id forgé.
        $this->assertStringContainsString('Profile::manageableBy($profileId, $user)', $corps);
        // Rien n'est écrit sur le profil : c'est la fiche qui enregistre.
        $this->assertStringNotContainsString('Profile::update', $corps);
        $this->assertStringNotContainsString('Profile::create', $corps);
    }

    public function testLeFormulaireRecoitLaBibliothequeEtLaDisponibiliteDeLAide(): void
    {
        $ctrl = self::lire('src/Controllers/AdminController.php');
        $pos  = strpos($ctrl, 'private function renderProfileForm(');
        $fin  = strpos($ctrl, 'public function generateProfilePrompt(', $pos);
        $corps = substr($ctrl, $pos, $fin - $pos);
        $this->assertStringContainsString('PromptStarters::all()', $corps);
        $this->assertStringContainsString('PromptStarters::skeleton()', $corps);
        $this->assertStringContainsString('$promptAiAvailable = SemanticChunker::resolveChunkingLlm() !== null', $corps);
    }

    public function testLeGabaritCableCartesPanneauEtGardes(): void
    {
        $g = self::lire('templates/admin/profiles/edit.php');
        $this->assertStringContainsString('class="pf-starter-card" data-starter=', $g);
        $this->assertStringContainsString('id="mpStarters"', $g);
        $this->assertStringContainsString('id="mpAiPanel"', $g);
        $this->assertStringContainsString('id="mpPromptUndo"', $g);
        $this->assertStringContainsString("fetch('/admin/profiles/prompt/generate'", $g);
        $this->assertStringContainsString('PromptStarters::BRIEF_MAX', $g);
        $this->assertStringContainsString('placeholder="<?= htmlspecialchars($promptSkeleton) ?>"', $g);
        // Le panneau IA n'existe que si un modèle est appelable : pas de bouton mort.
        $this->assertStringContainsString('<?php if ($promptAiAvailable): ?>', $g);
        // Jamais d'écrasement silencieux d'un texte écrit par le créateur.
        // (Texte traduit par tj() depuis le passage FR/EN de la fiche ; la forme littérale reste admise.)
        $this->assertMatchesRegularExpression("/confirm\\((?:'|<\\?= tj\\(')Remplacer le prompt actuel \\?/", $g);
        // Une affectation par script n'émet rien : l'auto-save ne verrait pas l'insertion.
        $this->assertStringContainsString("dispatchEvent(new Event('input',  { bubbles: true }))", $g);
        // Les cartes sont des boutons HORS soumission du formulaire.
        $this->assertMatchesRegularExpression('/<button type="button" class="pf-starter-card"/', $g);
        // Suggestions (description, température) posées seulement dans un champ VIDE.
        $this->assertStringContainsString("descEl.value.trim() === '' && st.description", $g);
        $this->assertStringContainsString("tNum.value === '' && typeof st.temperature === 'number'", $g);
    }

    /**
     * Mode relecture (édition) : un prompt posé par script n'est JAMAIS
     * enregistré tout seul — l'assistant est peut-être en service. L'auto-save
     * du formulaire entier est suspendu jusqu'à Enregistrer ou Annuler.
     */
    public function testEnEditionUnPromptPoseParScriptAttendEnregistrer(): void
    {
        $g = self::lire('templates/admin/profiles/edit.php');
        // La barre n'existe qu'en édition (la création a son bouton Créer) et
        // ses boutons ne soumettent pas le formulaire (type="button").
        $this->assertMatchesRegularExpression('#<\?php else: \?>.*id="mpReviewBar" hidden.*<\?php endif; \?>#s', $g);
        $this->assertMatchesRegularExpression('#<button type="button" class="btn-primary" id="mpReviewSave">(?:Enregistrer|<\\?= te\\(\'Enregistrer\'\\) \\?>)</button>#', $g);
        $this->assertStringContainsString('<button type="button" class="btn-secondary" id="mpReviewCancel"', $g);
        // Suspension lue par autosave.js à chaque évènement, posée AVANT l'émission des évènements.
        $this->assertStringContainsString("form.dataset.autosave = 'off';", $g);
        $this->assertMatchesRegularExpression('/enterReview\(\);.*\n\s*emitInput\(promptEl\);/', $g, 'enterReview() doit précéder emitInput() dans applyPrompt().');
        // Enregistrer = ré-armer puis envoyer ; Annuler = prompt enregistré restauré.
        $this->assertStringContainsString("form.dataset.autosave = '';", $g);
        $this->assertStringContainsString('window.MiaAutosave.save(form, 0)', $g);
        $this->assertStringContainsString('promptEl.value = savedPrompt;', $g);
        $this->assertStringContainsString("form.addEventListener('mia:autosaved', () => { savedPrompt = promptEl.value; })", $g);
        // Quitter la page en pleine relecture perdrait la proposition sans un mot.
        $this->assertMatchesRegularExpression('/beforeunload.*if \(!reviewing\) return;/s', $g);
        // Le mode n'existe que si le formulaire s'enregistre tout seul.
        $this->assertStringContainsString("const reviewable    = !!(form && form.hasAttribute('data-autosave'));", $g);
    }

    public function testClassesEtBootstrapChargentLaSourceUnique(): void
    {
        $this->assertStringContainsString("require ROOT . '/src/Assistants/PromptStarters.php';", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Assistants/PromptStarters.php',", self::lire('tests/bootstrap.php'));
        $this->assertStringContainsString('.pf-starter-card', self::lire('public/assets/css/main.css'));
    }

    public function testLeGuideCreateurDecritLesDeuxAides(): void
    {
        $guide = self::lire('docs/GUIDE-CREATEUR.md');
        $this->assertStringContainsString('Partir d\'un modèle', $guide);
        $this->assertStringContainsString('Rédiger avec l\'IA', $guide);
        $this->assertStringContainsString('Améliorer', $guide);
        $this->assertStringContainsString('crochets', $guide);
        $this->assertStringContainsString('pas encore enregistré', $guide);
    }
}
