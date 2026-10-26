<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Choix proposés en boutons : le contrat (ChoiceButtons), sa lecture par
 * l'écran (choice-buttons.js, partie PURE exécutée sous node) et son câblage.
 *
 * Deux moitiés du même contrat vivent dans deux langages : le prompt ENSEIGNE
 * une forme au modèle, le navigateur la LIT. Une lecture du code ne dit pas si
 * elles sont d'accord — d'où l'exemple du prompt, dans les deux langues,
 * rejoué par le vrai module : ce que MIA apprend à écrire est ce que l'écran
 * sait transformer en boutons.
 *
 * Sans node (conteneur applicatif), la partie JavaScript est SKIPPÉE ; elle
 * tourne sur le poste de développement et en CI.
 */
final class ChoiceButtonsTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $sortie = null;

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function lire(string $chemin): string
    {
        $contenu = file_get_contents(ROOT . '/' . $chemin);
        if (!is_string($contenu)) {
            self::fail("Fichier introuvable : $chemin");
        }

        return $contenu;
    }

    // ------------------------------------------------------------------
    // Contrat
    // ------------------------------------------------------------------

    public function testLesBornesSontCoherentes(): void
    {
        $this->assertGreaterThanOrEqual(2, ChoiceButtons::MIN_OPTIONS, 'Un « choix » à une option n\'en est pas un.');
        $this->assertLessThanOrEqual(ChoiceButtons::MAX_OPTIONS, ChoiceButtons::MIN_OPTIONS);
        $this->assertGreaterThanOrEqual(ChoiceButtons::MAX_OPTIONS, ChoiceButtons::OPTIONS_LIMIT, 'L\'écran doit accepter au moins ce que le prompt demande.');
        $this->assertLessThanOrEqual(9, ChoiceButtons::OPTIONS_LIMIT, 'Les raccourcis clavier vont de 1 à 9.');
        $this->assertLessThan(ChoiceButtons::LABEL_MAX, ChoiceButtons::COMPACT_LABEL_MAX);
    }

    public function testLeNavigateurRecoitLeContratEntier(): void
    {
        $config = ChoiceButtons::clientConfig();
        $this->assertSame(ChoiceButtons::LANGUAGES, $config['languages']);
        $this->assertSame(ChoiceButtons::MULTIPLE_FLAGS, $config['multiple']);
        $this->assertSame(ChoiceButtons::MIN_OPTIONS, $config['min']);
        $this->assertSame(ChoiceButtons::OPTIONS_LIMIT, $config['limit']);
        $this->assertSame(ChoiceButtons::LABEL_MAX, $config['labelMax']);
        $this->assertSame(ChoiceButtons::DESCRIPTION_MAX, $config['descriptionMax']);
        $this->assertSame(ChoiceButtons::QUESTION_MAX, $config['questionMax']);
        $this->assertSame(ChoiceButtons::COMPACT_LABEL_MAX, $config['compactMax']);

        // Le repli du module (sans window.CHOICE_BUTTONS) dit la même chose.
        $js = self::lire('public/assets/js/choice-buttons.js');
        $this->assertStringContainsString("languages: ['" . implode("', '", ChoiceButtons::LANGUAGES) . "']", $js);
        $this->assertStringContainsString("multiple: ['" . implode("', '", ChoiceButtons::MULTIPLE_FLAGS) . "']", $js);
        $this->assertStringContainsString('limit: ' . ChoiceButtons::OPTIONS_LIMIT . ',', $js);
    }

    /** Le prompt enseigne, dans chaque langue, un langage de bloc que l'écran lit. */
    public function testLePromptEnseigneUnLangageQueLEcranLit(): void
    {
        foreach (['fr' => 'choix', 'en' => 'choices'] as $langue => $attendu) {
            I18n::use($langue);
            $prompt = ChoiceButtons::promptFragment();

            preg_match_all('/```([a-z]+)/', $prompt, $m);
            $this->assertNotEmpty($m[1], "Aucun bloc enseigné ($langue).");
            foreach ($m[1] as $langage) {
                $this->assertContains($langage, ChoiceButtons::LANGUAGES, "Le prompt $langue enseigne ```$langage, que l'écran ne lit pas.");
            }
            $this->assertContains($attendu, $m[1]);
            // Le choix multiple enseigné est un drapeau reconnu.
            $this->assertMatchesRegularExpression('/```' . $attendu . ' (' . implode('|', ChoiceButtons::MULTIPLE_FLAGS) . ')\b/', $prompt);
            // Bornes substituées, jamais laissées en accolades.
            $this->assertStringNotContainsString('{min}', $prompt);
            $this->assertStringContainsString((string)ChoiceButtons::MAX_OPTIONS, $prompt);
        }
    }

    public function testLePromptDitQuandNePasProposerDeChoix(): void
    {
        I18n::use('fr');
        $prompt = ChoiceButtons::promptFragment();
        $this->assertStringContainsString('Seulement quand', $prompt);
        $this->assertStringContainsString('question ouverte', $prompt);
        $this->assertStringContainsString('approuver une action préparée', $prompt, 'Une carte d\'approbation a déjà ses boutons.');
        $this->assertStringContainsString('« Autre »', $prompt);
    }

    // ------------------------------------------------------------------
    // Lecture du bloc par l'écran (node)
    // ------------------------------------------------------------------

    public function testLaLigneDOuvertureDitSiLeChoixEstMultiple(): void
    {
        $r = self::sortie()['parseInfo'];
        $this->assertSame(['multiple' => false], $r['choix']);
        $this->assertSame(['multiple' => false], $r['CHOICES']);
        $this->assertSame(['multiple' => false], $r['{.choix}']);
        $this->assertSame(['multiple' => true], $r['choix multiple']);
        $this->assertSame(['multiple' => true], $r['choices multiple']);
        $this->assertSame(['multiple' => true], $r['choix plusieurs']);
        $this->assertSame(['multiple' => true], $r['choix multiple="true"']);
        $this->assertSame(['multiple' => false], $r['choix multiple=false']);
        // Blocs ordinaires : jamais de carte.
        $this->assertNull($r['python']);
        $this->assertNull($r['']);
        $this->assertNull($r['markdown']);
        $this->assertNull($r['text choix']);
    }

    /** L'exemple du prompt, dans les deux langues : une question, trois options, la première précisée. */
    public function testLExempleDuPromptDevientTroisBoutons(): void
    {
        foreach (['fr' => ["Quel ton pour l'email ?", 'Formel', 'pour un client'], 'en' => ['Which tone for the email?', 'Formal', 'for a client']] as $langue => [$question, $premier, $precision]) {
            $choix = self::sortie()['exemple'][$langue];
            $this->assertNotNull($choix, "L'exemple du prompt $langue n'est pas lu comme un bloc de choix.");
            $this->assertTrue($choix['valid']);
            $this->assertFalse($choix['multiple']);
            $this->assertSame($question, $choix['question']);
            $this->assertCount(3, $choix['options']);
            $this->assertSame(['label' => $premier, 'description' => $precision], $choix['options'][0]);
            $this->assertSame('', $choix['options'][1]['description']);
        }
    }

    public function testLesOptionsSontLuesAvecTolerance(): void
    {
        $c = self::sortie()['corps'];

        // Puces, numéros, cases à cocher ; gras et code retirés du libellé.
        $this->assertSame(
            [['label' => 'Résumé court', 'description' => 'une page'], ['label' => 'Analyse détaillée', 'description' => ''], ['label' => 'Les deux', 'description' => '']],
            $c['puces']['options']
        );
        $this->assertSame('Quelle approche ?', $c['puces']['question']);
        $this->assertSame(['Oui', 'Non', 'Plus tard'], array_column($c['numeros']['options'], 'label'));
        $this->assertSame(['Excel', 'Word'], array_column($c['cases']['options'], 'label'));

        // Un tiret simple ne sépare pas : « Plan A - rapide » reste un libellé.
        $this->assertSame([['label' => 'Plan A - rapide', 'description' => ''], ['label' => 'Plan B', 'description' => 'plus sûr']], $c['tirets']['options']);

        // Doublons (casse comprise) retirés ; au-delà de la limite, ignorées.
        $this->assertSame(['Oui', 'Non'], array_column($c['doublons']['options'], 'label'));
        $this->assertCount(ChoiceButtons::OPTIONS_LIMIT, $c['trop']['options']);

        // Libellé coupé à sa longueur maximale (points de code).
        $this->assertSame(ChoiceButtons::LABEL_MAX, mb_strlen($c['long']['options'][0]['label']));

        // Sans puce : la première ligne interrogative est la question.
        $this->assertSame('Vous préférez ?', $c['sans_puces']['question']);
        $this->assertSame(['Thé', 'Café'], array_column($c['sans_puces']['options'], 'label'));
        $this->assertSame('', $c['sans_puces_ni_question']['question']);
        $this->assertSame(['Rouge', 'Vert', 'Bleu'], array_column($c['sans_puces_ni_question']['options'], 'label'));

        // Une remarque APRÈS les options n'en devient pas une.
        $this->assertSame(['A', 'B'], array_column($c['remarque']['options'], 'label'));
    }

    public function testUnBlocSansAssezDOptionsNestPasUneCarte(): void
    {
        $s = self::sortie();
        $this->assertFalse($s['valide']['une_option']);
        $this->assertFalse($s['valide']['vide']);
        $this->assertTrue($s['valide']['deux_options']);
    }

    public function testLesOptionsCourtesSansPrecisionSontDesPastilles(): void
    {
        $s = self::sortie()['compact'];
        $this->assertTrue($s['oui_non']);
        $this->assertFalse($s['avec_precision']);
        $this->assertFalse($s['multiple'], 'Un choix multiple garde ses cases.');
        $this->assertFalse($s['long']);
    }

    public function testLaCopieEnTexteBrutRemplaceLaSyntaxeParLaListe(): void
    {
        $t = self::sortie()['texte'];
        $this->assertSame("Voici trois pistes.\n\nQuelle approche ?\n- Résumé court — une page\n- Analyse détaillée", $t['simple']);
        // Un bloc d'une autre langue n'est jamais touché, même s'il contient « ```choix ».
        $this->assertSame($t['code_entree'], $t['code']);
        // Bloc jamais refermé (réponse interrompue) : la liste quand même.
        $this->assertSame("Question ?\n- A\n- B", $t['ouvert']);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLaClasseEstChargeeParLesDeuxListes(): void
    {
        $this->assertStringContainsString("require ROOT . '/src/Helpers/ChoiceButtons.php';", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Helpers/ChoiceButtons.php'", self::lire('tests/bootstrap.php'));
    }

    /** Envoi : seulement quand quelqu'un regarde l'écran. Régénération : toujours. */
    public function testLeFragmentNestPasEnvoyeSansPersonneDevantLEcran(): void
    {
        $code = self::lire('src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count($code, 'ChoiceButtons::promptFragment()'));
        $this->assertMatchesRegularExpression('/if \(!\$unattended && !\$voiceMode\) \{\s*\$systemPrompt \.= ChoiceButtons::promptFragment\(\);/', $code);
    }

    public function testLaPageChargeLeModuleEntreLesFichiersEtLeChat(): void
    {
        $page = self::lire('templates/chat/index.php');
        $fichiers = strpos($page, '/assets/js/generated-files.js');
        $choix    = strpos($page, '/assets/js/choice-buttons.js');
        $chat     = strpos($page, '/assets/js/chat.js');
        $this->assertNotFalse($choix);
        $this->assertGreaterThan($fichiers, $choix, 'Après generated-files.js : il en reprend la lecture d\'un bloc refermé.');
        $this->assertLessThan($chat, $choix, 'Avant chat.js : il rend les messages dès son chargement.');
        $this->assertStringContainsString('window.CHOICE_BUTTONS = <?= json_encode(ChoiceButtons::clientConfig()', $page);
        $this->assertStringContainsString('/assets/css/choices.css', $page);
    }

    public function testLeChatEnvoieLeChoixCommeUnMessageOrdinaire(): void
    {
        $chat = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('async function submitForm(override = null)', $chat);
        // Le brouillon et les fichiers en attente restent dans le composer.
        $this->assertStringContainsString('const snapshotFiles = fromChoice ? [] : [...pendingFiles];', $chat);
        $this->assertStringContainsString('if (!fromChoice) commitComposerDraft(text);', $chat);
        $this->assertStringContainsString('if (!fromChoice) clearComposerDraft();', $chat);
        $this->assertStringContainsString("await submitForm({ text: values.join('\\n') });", $chat);
    }

    public function testLEtatDesCartesSuitLeFil(): void
    {
        $chat = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('function syncChoicePrompts()', $chat);
        // Après chaque message ajouté, aux moments du bouton « Continuer », et
        // à la fin des deux transports (flux du job, flux de régénération).
        $append = substr($chat, strpos($chat, 'function appendMessage('), 12000);
        $this->assertStringContainsString('syncChoicePrompts();', substr($append, 0, strpos($append, "\n}\n")));
        $continue = substr($chat, strpos($chat, 'function syncContinueButton()'), 400);
        $this->assertStringContainsString('syncChoicePrompts();', $continue);
        $this->assertGreaterThanOrEqual(5, substr_count($chat, 'syncChoicePrompts();'));
    }

    public function testCopieEtExportsNeGardentQueLaListe(): void
    {
        $chat = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('window.MiaChoices?.flattenForExport(wrapper);', $chat);
        $this->assertStringContainsString('window.MiaChoices.toPlainText(raw)', $chat);
    }

    /** Le rendu rend la main pour tout autre bloc : les cartes de fichier et le code ordinaire passent. */
    public function testLeRenduRendLaMainAuxAutresBlocs(): void
    {
        $js = self::lire('public/assets/js/choice-buttons.js');
        $this->assertStringContainsString("if (parseInfo(token.lang || '') === null) return false;", $js);
        // Rendu sans état : les boutons sortent toujours inertes.
        $this->assertStringContainsString('class="choice-option" disabled', $js);
        // Dans l'aperçu d'un document livré, du texte : pas une question posée.
        $this->assertStringContainsString('files.isPreviewing()', $js);
        $this->assertStringContainsString('isPreviewing: function () { return previewDepth > 0; }', self::lire('public/assets/js/generated-files.js'));
    }

    public function testLaFeuilleDeStyleSuitLesReglesDuChat(): void
    {
        $css = self::lire('public/assets/css/choices.css');
        $this->assertStringContainsString('@media (hover: hover)', $css, 'Survols jamais au doigt.');
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('min-height: 44px', $css, 'Cibles de 44 px au doigt.');
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{6}\b/i', preg_replace('/#fff\b/', '', $css) ?? '', 'Couleurs = jetons du thème.');
    }

    public function testLaFonctionnaliteEstDocumentee(): void
    {
        $this->assertStringContainsString('<!-- id: choix-en-boutons ', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Répondre à un choix proposé', self::lire('docs/user/fonctionnalites/conversations/mener-une-conversation.md'));
        $this->assertStringContainsString('choix en boutons', mb_strtolower(self::lire('docs/GUIDE-UTILISATEUR.md')));
    }

    // ------------------------------------------------------------------
    // Exécution sous node
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function sortie(): array
    {
        if (self::$sortie !== null) {
            return self::$sortie;
        }
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        $exemples = [];
        foreach (['fr', 'en'] as $langue) {
            I18n::use($langue);
            $prompt = ChoiceButtons::promptFragment();
            if (!preg_match('/```([a-z]+)\n(.*?)\n```/s', $prompt, $m)) {
                self::fail("Exemple introuvable dans le prompt $langue.");
            }
            $exemples[$langue] = ['info' => $m[1], 'body' => $m[2]];
        }
        I18n::use('fr');

        $longue = str_repeat('é', ChoiceButtons::LABEL_MAX + 30);
        $entree = [
            'infos' => ['choix', 'CHOICES', '{.choix}', 'choix multiple', 'choices multiple', 'choix plusieurs',
                'choix multiple="true"', 'choix multiple=false', 'python', '', 'markdown', 'text choix'],
            'exemples' => $exemples,
            'corps' => [
                'puces'    => "**Quelle approche ?**\n- **Résumé court** — une page\n* Analyse `détaillée`\n+ Les deux",
                'numeros'  => "1. Oui\n2) Non\n3. Plus tard",
                'cases'    => "- [ ] Excel\n- [x] Word",
                'tirets'   => "- Plan A - rapide\n- Plan B – plus sûr",
                'doublons' => "- Oui\n- oui\n- Non",
                'trop'     => implode("\n", array_map(static fn(int $i): string => "- Option $i", range(1, 12))),
                'long'     => "- $longue\n- Court",
                'sans_puces' => "Vous préférez ?\nThé\nCafé",
                'sans_puces_ni_question' => "Rouge\nVert\nBleu",
                'remarque' => "- A\n- B\nVous pouvez aussi préciser.",
            ],
            'valide' => [
                'une_option'   => "Question ?\n- Seule",
                'vide'         => '',
                'deux_options' => "- A\n- B",
            ],
            'compact' => [
                'oui_non'        => ['choix', "- Oui\n- Non"],
                'avec_precision' => ['choix', "- Oui — tout de suite\n- Non"],
                'multiple'       => ['choix multiple', "- Oui\n- Non"],
                'long'           => ['choix', "- Une option nettement trop longue pour une pastille\n- Non"],
            ],
            'texte' => [
                'simple' => "Voici trois pistes.\n\n```choix\nQuelle approche ?\n- Résumé court — une page\n- **Analyse détaillée**\n```",
                'code'   => "```python\nprint('```choix')\n- A\n```\nFin.",
                'ouvert' => "```choix\nQuestion ?\n- A\n- B",
            ],
        ];

        $script = "'use strict';\n"
            . 'globalThis.CHOICE_BUTTONS = ' . json_encode(ChoiceButtons::clientConfig()) . ";\n"
            . self::lire('public/assets/js/choice-buttons.js') . "\n"
            . 'const ENTREE = ' . json_encode($entree) . ";\n"
            . self::SCENARIOS;

        $fichier = tempnam(sys_get_temp_dir(), 'mia-choix-js-');
        file_put_contents($fichier, $script);
        try {
            $processus = proc_open([$node, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            if (!is_resource($processus)) {
                self::fail('Impossible de lancer node.');
            }
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $code = proc_close($processus);
        } finally {
            @unlink($fichier);
        }

        $resultats = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultats)) {
            self::fail("choice-buttons.js a échoué sous node (code $code) :\n" . $erreurs);
        }

        return self::$sortie = $resultats;
    }

    private const SCENARIOS = <<<'JS'
        const M = globalThis.MiaChoices;
        if (!M || typeof M.parse !== 'function') {
            throw new Error('MiaChoices absent : la partie pure du module ne s’est pas chargée.');
        }
        if (typeof M.applyState === 'function') {
            throw new Error('La partie DOM ne doit pas se charger sans document.');
        }
        const out = { parseInfo: {}, exemple: {}, corps: {}, valide: {}, compact: {}, texte: {} };
        ENTREE.infos.forEach((info) => { out.parseInfo[info] = M.parseInfo(info); });
        Object.keys(ENTREE.exemples).forEach((l) => {
            out.exemple[l] = M.parse(ENTREE.exemples[l].info, ENTREE.exemples[l].body);
        });
        Object.keys(ENTREE.corps).forEach((k) => { out.corps[k] = M.parseBody(ENTREE.corps[k]); });
        Object.keys(ENTREE.valide).forEach((k) => { out.valide[k] = M.parse('choix', ENTREE.valide[k]).valid; });
        Object.keys(ENTREE.compact).forEach((k) => {
            out.compact[k] = M.isCompact(M.parse(ENTREE.compact[k][0], ENTREE.compact[k][1]));
        });
        out.texte.simple = M.toPlainText(ENTREE.texte.simple);
        out.texte.code_entree = ENTREE.texte.code;
        out.texte.code = M.toPlainText(ENTREE.texte.code);
        out.texte.ouvert = M.toPlainText(ENTREE.texte.ouvert);
        process.stdout.write(JSON.stringify(out));
        JS;

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }

        return null;
    }
}
